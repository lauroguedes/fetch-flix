<?php

use App\Media\Events\BotDetected;
use App\Media\Listeners\ReportBotDetection;
use App\Media\Listeners\ReportExtractorBroken;
use App\Media\YtDlp\YtDlpErrorClassifier;
use App\Media\YtDlp\YtDlpFailed;
use App\Media\YtDlp\YtDlpRunner;
use App\Models\ExtractorHealth;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\UsageRecord;
use App\Models\VideoCategory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeYtDlpRunner;
use Tests\Support\YouTubeApiFake;

it('serves health without a token', function () {
    // Must stay reachable when token issuance or the database is the thing that
    // is broken.
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonStructure([
            'status',
            'extractor' => ['state', 'yt_dlp_version', 'checked_at', 'failure_code', 'failures_last_hour', 'bot_checks_last_hour'],
            'discovery' => ['quota_remaining', 'quota_resets_in'],
            'request_id',
        ]);
});

it('reports degraded when the last self-test failed', function () {
    ExtractorHealth::factory()->broken()->create();

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('extractor.state', 'broken')
        ->assertJsonPath('extractor.failure_code', 'resolver_unavailable');
});

it('reports unknown rather than healthy when no check has run', function () {
    // Silence is not health. Claiming "ok" before anything was verified is how
    // a broken extractor stays invisible.
    $this->getJson('/api/v1/health')->assertJsonPath('extractor.state', 'unknown');
});

it('surfaces the bot check rate that drives mitigation decisions', function () {
    (new ReportBotDetection)->handle(new BotDetected("Sign in to confirm you're not a bot"));
    (new ReportBotDetection)->handle(new BotDetected("Sign in to confirm you're not a bot"));

    $this->getJson('/api/v1/health')->assertJsonPath('extractor.bot_checks_last_hour', 2);
});

it('counts unclassified extractor failures through the real classifier', function () {
    (new YtDlpErrorClassifier)->classify(new YtDlpFailed(stderr: 'ERROR: never seen before', exitCode: 1));

    expect(ReportExtractorBroken::countThisHour())->toBe(1);
});

it('does not count an ordinary permanent failure as extractor breakage', function () {
    (new YtDlpErrorClassifier)->classify(new YtDlpFailed(stderr: 'ERROR: Video unavailable', exitCode: 1));

    // A deleted video is not a broken extractor; alerting on it buries the
    // signal that matters.
    expect(ReportExtractorBroken::countThisHour())->toBe(0);
});

it('records a self-test result', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    $this->artisan('yt-dlp:selftest')->assertSuccessful();

    $health = ExtractorHealth::query()->firstOrFail();

    expect($health->ok)->toBeTrue()
        ->and($health->duration_ms)->toBeInt();
});

it('records a failing self-test and exits non-zero so a scheduler notices', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::failingWith('ERROR: nsig extraction failed'));

    $this->artisan('yt-dlp:selftest')->assertFailed();

    $health = ExtractorHealth::query()->firstOrFail();

    expect($health->ok)->toBeFalse()
        ->and($health->failure_code)->toBe('resolver_unavailable');
});

it('enforces the per-tenant resolve rate limit in our error envelope', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    $tenant = Tenant::factory()->create(['rate_limit_rpm' => 2]);

    resolveAs($tenant, [], ['X-Request-Id' => 'a'])->assertOk();
    resolveAs($tenant, [], ['X-Request-Id' => 'b'])->assertOk();

    $limited = resolveAs($tenant, [], ['X-Request-Id' => 'c']);

    // The framework's default 429 body is not the shape consumers parse.
    $limited->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertJsonStructure(['status', 'code', 'message', 'retry_after', 'request_id']);

    expect($limited->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
});

it('limits tenants independently', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    $noisy = Tenant::factory()->create(['rate_limit_rpm' => 1]);
    $quiet = Tenant::factory()->create(['rate_limit_rpm' => 10]);

    resolveAs($noisy, [], ['X-Request-Id' => 'a'])->assertOk();
    resolveAs($noisy, [], ['X-Request-Id' => 'b'])->assertStatus(429);

    // One noisy consumer must not degrade anybody else.
    resolveAs($quiet, [], ['X-Request-Id' => 'c'])->assertOk();
});

it('warms category feeds from a dedicated budget', function () {
    config()->set('media.discovery.youtube_api_key', 'test-key');
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    VideoCategory::factory()->create(['slug' => 'comedy']);
    VideoCategory::factory()->create(['slug' => 'music']);
    VideoCategory::factory()->inactive()->create(['slug' => 'retired']);

    $this->artisan('discover:warm')->assertSuccessful();

    // Two active categories only; the retired one must not spend anything.
    expect(YouTubeApiFake::dataApiCalls())->toBe(4);

    // Spent by the system warmer, never against a paying tenant's allowance.
    expect(Tenant::query()->where('slug', 'system-warmer')->exists())->toBeTrue();
});

it('makes a warmed category free for the next real caller', function () {
    config()->set('media.discovery.youtube_api_key', 'test-key');
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);
    VideoCategory::factory()->create(['slug' => 'comedy']);

    $this->artisan('discover:warm')->assertSuccessful();
    $afterWarm = YouTubeApiFake::dataApiCalls();

    discoverAs(Tenant::factory()->create(), ['kind' => 'category', 'category' => 'comedy', 'query' => null])
        ->assertOk();

    // The whole point: a user request costs nothing because the schedule
    // already paid for it off-peak.
    expect(YouTubeApiFake::dataApiCalls())->toBe($afterWarm);
});

it('stops warming rather than draining the budget when quota runs out', function () {
    config()->set('media.discovery.youtube_api_key', 'test-key');
    config()->set('media.quota.reserved_for_warming', 100);
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    foreach (['a', 'b', 'c', 'd'] as $slug) {
        VideoCategory::factory()->create(['slug' => $slug]);
    }

    $this->artisan('discover:warm');

    // One search (100) exhausts the reserved budget; the rest must not be
    // attempted.
    expect(YouTubeApiFake::dataApiCalls())->toBeLessThan(4);
});

it('prunes usage records past their retention window', function () {
    Http::fake();
    $tenant = Tenant::factory()->create();

    UsageRecord::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDays(120)]);
    UsageRecord::factory()->create(['tenant_id' => $tenant->id, 'created_at' => now()->subDay()]);

    $this->artisan('schedule:test --name=prune-retention')->assertSuccessful();

    // Retention is enforced, not left to somebody remembering.
    expect(UsageRecord::query()->count())->toBe(1);
});

it('fails loudly when yt-dlp cannot be found rather than reporting success', function () {
    config()->set('media.yt_dlp.binary', '/nonexistent/yt-dlp');

    // A maintenance command that silently no-ops leaves the extractor stale
    // while the schedule reports everything is fine.
    $this->artisan('yt-dlp:update')->assertFailed();
});

it('reports a self-test failure when the control video cannot be resolved', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::failingWith('ERROR: Video unavailable'));

    // The control video being gone is itself a broken health check: it must not
    // be mistaken for a healthy extractor.
    $this->artisan('yt-dlp:selftest')->assertFailed();

    expect(ExtractorHealth::query()->firstOrFail()->failure_code)->toBe('unresolvable');
});

it('reports storage state on the health probe', function () {
    // Storage being down is a full outage of /resolve now that the bytes are
    // ours, so it belongs in the top-level status rather than a footnote.
    MediaAsset::factory()->stored()->create(['bytes' => 1024]);
    MediaAsset::factory()->failed()->create();

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('storage.reachable', true)
        ->assertJsonPath('storage.driver', 'local')
        ->assertJsonPath('storage.stored', 1)
        ->assertJsonPath('storage.failed', 1)
        ->assertJsonPath('storage.bytes', 1024);
});

it('degrades the health probe when media storage is unreachable', function () {
    config()->set('media.storage.disk', 'nonexistent-disk');

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('storage.reachable', false);
});
