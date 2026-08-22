<?php

use App\Media\Jobs\ResolveMediaJob;
use App\Media\YtDlp\YtDlpRunner;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeYtDlpRunner;

/**
 * The mobile client is in production shape and its error handling is already
 * written, so these tests pin the wire format rather than describing it.
 */
function resolveAs(Tenant $tenant, array $overrides = [], array $headers = []): TestResponse
{
    // Laravel's auth guards cache the resolved user, and a test keeps one
    // container alive across many requests, so without this the second call in
    // a test is still attributed to the first caller. Real requests get a fresh
    // container (and Octane flushes guards), so this restores production
    // behaviour rather than papering over a bug.
    app('auth')->forgetGuards();

    $token = $tenant->createToken('test', [Tenant::ABILITY_RESOLVE])->plainTextToken;

    $payload = array_replace([
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'prefer' => 'progressive',
        'max_height' => 720,
        'audio_only' => false,
        'client' => ['platform' => 'ios', 'app_version' => '1.4.2', 'api_version' => 'v1'],
    ], $overrides);

    return test()->withToken($token)->postJson('/api/v1/resolve', $payload, $headers);
}

function fakeRunner(FakeYtDlpRunner $runner): FakeYtDlpRunner
{
    app()->instance(YtDlpRunner::class, $runner);

    return $runner;
}

it('returns every key the client contract specifies', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create())
        ->assertOk()
        ->assertJsonStructure([
            'status', 'provider', 'id', 'title', 'author', 'duration_ms', 'thumbnail_url',
            'media' => [
                'url', 'mime_type', 'container', 'bytes', 'width', 'height',
                'has_audio', 'has_video', 'headers', 'expires_at',
            ],
            'request_id',
        ])
        ->assertJson([
            'status' => 'ok',
            'provider' => 'youtube',
            'id' => 'dQw4w9WgXcQ',
            'duration_ms' => 213000,
        ]);
});

it('emits bytes as null rather than dropping the key', function () {
    // A client reading a fixed shape must not have a key vanish underneath it.
    $info = FakeYtDlpRunner::fixture('progressive-only');
    foreach ($info['formats'] as $i => $f) {
        unset($info['formats'][$i]['filesize'], $info['formats'][$i]['filesize_approx']);
    }
    fakeRunner(FakeYtDlpRunner::withInfo($info));

    $response = resolveAs(Tenant::factory()->create(), ['max_height' => 360]);

    $response->assertOk()->assertJsonPath('media.bytes', null);
    expect($response->json('media'))->toHaveKey('bytes');
});

it('never hands back an HLS playlist as the downloadable url', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    $response = resolveAs(Tenant::factory()->create(), ['max_height' => 1080]);

    expect($response->json('media.url'))->not->toContain('.m3u8')
        ->and($response->json('media.has_audio'))->toBeTrue()
        ->and($response->json('media.has_video'))->toBeTrue();
});

it('returns 200 with truthful dimensions when it has to degrade', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    // itag 22 is gone, so 720p is not available as a muxed file. Erroring here
    // would break every ordinary request; the client sizes its frame from the
    // real width/height we return.
    resolveAs(Tenant::factory()->create(), ['max_height' => 720])
        ->assertOk()
        ->assertJsonPath('media.height', 360)
        ->assertJsonPath('media.width', 640)
        ->assertJsonPath('media.degraded', true)
        ->assertJsonPath('media.requested_height', 720);
});

it('passes cdn headers through verbatim in passthrough mode', function () {
    // Only meaningful when the client downloads from the provider. Under
    // storage delivery it downloads from us and there is nothing to pass.
    config()->set('media.delivery', 'passthrough');
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    $response = resolveAs(Tenant::factory()->create(), ['max_height' => 360]);

    // Some CDNs 403 without these, and the client feeds them straight into
    // URLSession.
    $headers = $response->json('media.headers');

    expect($headers)->toHaveKey('User-Agent')
        ->and($headers['User-Agent'])->toContain('Mozilla/5.0');
});

it('returns an ISO-8601 expiry parsed from the stream url in passthrough mode', function () {
    config()->set('media.delivery', 'passthrough');
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    // Fixture urls carry expire=2051222400 (2035-01-01Z); the advertised value
    // is pulled back by the 120s safety margin.
    resolveAs(Tenant::factory()->create(), ['max_height' => 360])
        ->assertOk()
        ->assertJsonPath('media.expires_at', '2034-12-31T23:58:00Z');
});

it('resolves audio only when asked', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create(), ['audio_only' => true])
        ->assertOk()
        ->assertJsonPath('media.has_video', false)
        ->assertJsonPath('media.has_audio', true);
});

it('rejects a video longer than the request allows without retry advice', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create(), ['max_duration_ms' => 60000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'too_long')
        ->assertJsonPath('retry_after', null);
});

it('applies the tenant duration ceiling even when the request asks for more', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    $tenant = Tenant::factory()->create(['max_duration_ms' => 60000]);

    resolveAs($tenant, ['max_duration_ms' => 14400000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'too_long');
});

it('maps a removed video to a permanent error the client will not retry', function () {
    fakeRunner(FakeYtDlpRunner::failingWith('ERROR: [youtube] abc: Video unavailable'));

    resolveAs(Tenant::factory()->create())
        ->assertStatus(422)
        ->assertJsonPath('code', 'unresolvable')
        ->assertHeaderMissing('Retry-After');
});

it('maps a bot check to a retryable 503', function () {
    fakeRunner(FakeYtDlpRunner::failingWith("ERROR: Sign in to confirm you're not a bot"));

    resolveAs(Tenant::factory()->create())
        ->assertStatus(503)
        ->assertJsonPath('code', 'resolver_unavailable')
        ->assertHeader('Retry-After');
});

it('rejects an unsupported provider url as permanently unresolvable', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create(), ['url' => 'https://vimeo.com/12345'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unresolvable');
});

it('rejects a prefer value it cannot honour', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    // The client cannot mux DASH, so accepting "adaptive" would mean promising
    // something unusable.
    resolveAs(Tenant::factory()->create(), ['prefer' => 'adaptive'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_request');
});

it('requires the client block', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    $tenant = Tenant::factory()->create();
    $token = $tenant->createToken('t', [Tenant::ABILITY_RESOLVE])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/resolve', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
        'prefer' => 'progressive',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_request');
});

it('runs extraction once for a repeated request id', function () {
    $runner = fakeRunner(FakeYtDlpRunner::withFixture('standard'));
    $tenant = Tenant::factory()->create();
    $headers = ['X-Request-Id' => 'user-action-42'];

    $first = resolveAs($tenant, [], $headers)->assertOk();
    $second = resolveAs($tenant, [], $headers)->assertOk();

    // Extraction is expensive; running it twice for one user action is waste.
    expect($runner->calls)->toBe(1)
        ->and($second->json('media.url'))->toBe($first->json('media.url'))
        ->and($second->json('request_id'))->toBe('user-action-42');
});

it('shares a resolution across tenants asking for the same video', function () {
    $runner = fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create(), [], ['X-Request-Id' => 'a'])->assertOk();
    resolveAs(Tenant::factory()->create(), [], ['X-Request-Id' => 'b'])->assertOk();

    expect($runner->calls)->toBe(1);
});

it('does not leak one tenant request id replay to another', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $first = resolveAs($a, [], ['X-Request-Id' => 'shared-id'])->assertOk();
    $second = resolveAs($b, [], ['X-Request-Id' => 'shared-id'])->assertOk();

    expect($second->json('request_id'))->toBe('shared-id')
        ->and($first->json('request_id'))->toBe('shared-id');
});

it('queues a re-extraction when the deadline is missed so the retry is warm', function () {
    Queue::fake();
    fakeRunner(FakeYtDlpRunner::timingOut());

    resolveAs(Tenant::factory()->create())
        ->assertStatus(503)
        ->assertJsonPath('code', 'resolver_unavailable')
        ->assertJsonPath('retry_after', 2);

    // The work is not thrown away: the client's retry a couple of seconds later
    // lands on a warm cache instead of a second slow extraction.
    Queue::assertPushed(ResolveMediaJob::class);
});

it('requires the resolve ability', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('standard'));

    $tenant = Tenant::factory()->create();
    $token = $tenant->createToken('t', [Tenant::ABILITY_DISCOVER])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/resolve', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
        'prefer' => 'progressive',
        'client' => ['platform' => 'ios', 'app_version' => '1', 'api_version' => 'v1'],
    ])->assertStatus(403)->assertJsonPath('code', 'unauthorized');
});

it('rejects an unauthenticated resolve', function () {
    $this->postJson('/api/v1/resolve', [])->assertStatus(403);
});
