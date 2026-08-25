<?php

use App\Media\Data\AssetStatus;
use App\Media\Jobs\DownloadMediaAsset;
use App\Models\MediaAsset;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeYtDlpRunner;

/**
 * What /resolve returns once the service serves its own bytes.
 *
 * The shape of the response is unchanged and that is the point: the client in
 * production reads the same keys and needs no release.
 */
it('hands back a signed url of ours rather than the provider url', function () {
    // The provider's URL carries the IP that extracted it. A server resolving
    // and a phone downloading is a guaranteed 403, which is the entire reason
    // this mode exists.
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    $url = resolveAs(Tenant::factory()->create(), ['max_height' => 360])
        ->assertOk()
        ->json('media.url');

    expect($url)->toStartWith(config('app.url').'/api/v1/stream/')
        ->and($url)->toContain('signature=')
        ->and($url)->not->toContain('googlevideo.com');
});

it('keeps every contracted key present', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])
        ->assertOk()
        ->assertJsonStructure([
            'status', 'provider', 'id', 'title', 'author', 'duration_ms', 'thumbnail_url',
            'media' => [
                'url', 'mime_type', 'container', 'bytes', 'width', 'height',
                'has_audio', 'has_video', 'headers', 'expires_at',
            ],
            'request_id',
        ]);
});

it('emits headers as an empty object rather than dropping the key', function () {
    // Our own URL needs no Referer and no User-Agent, but a client reading a
    // fixed shape must not have the key vanish underneath it.
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    $response = resolveAs(Tenant::factory()->create(), ['max_height' => 360]);

    expect($response->json('media'))->toHaveKey('headers')
        ->and($response->json('media.headers'))->toBe([]);

    expect($response->getContent())->toContain('"headers":{}');
});

it('quotes an expiry of ours, not the provider expiry', function () {
    config()->set('media.storage.signed_ttl', 3600);
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    $expiresAt = resolveAs(Tenant::factory()->create(), ['max_height' => 360])
        ->assertOk()
        ->json('media.expires_at');

    // The fixture URL expires in 2035. Ours is an hour out, chosen by us and
    // reissued on every resolve.
    expect(now()->diffInMinutes(Carbon\Carbon::parse($expiresAt)))
        ->toBeGreaterThan(55)
        ->toBeLessThan(65);
});

it('keeps truthful dimensions when it has to degrade', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 720])
        ->assertOk()
        ->assertJsonPath('media.height', 360)
        ->assertJsonPath('media.degraded', true)
        ->assertJsonPath('media.requested_height', 720);
});

it('queues the transfer and records what was chosen', function () {
    Queue::fake();
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();

    Queue::assertPushed(DownloadMediaAsset::class);

    $asset = MediaAsset::query()->sole();

    expect($asset->provider_video_id)->toBe('dQw4w9WgXcQ')
        ->and($asset->status)->toBe(AssetStatus::Pending)
        ->and($asset->format_id)->toBe('18')
        ->and($asset->height)->toBe(360);
});

it('answers a warm resolve without extracting anything', function () {
    // The saving this whole key scheme exists for: an 8s process spawn becomes
    // one indexed read.
    $runner = fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();

    MediaAsset::query()->sole()->forceFill([
        'status' => AssetStatus::Stored,
        'disk' => 'media',
        'path' => 'youtube/ab/dQw4w9WgXcQ/18.mp4',
        'bytes' => 4242,
    ])->save();

    // Both caches cleared: this must come from the database, not from Redis.
    cache()->clear();

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])
        ->assertOk()
        ->assertJsonPath('media.bytes', 4242);

    expect($runner->calls)->toBe(1);
});

it('does not queue a second transfer for a file it already holds', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();

    MediaAsset::query()->sole()->forceFill([
        'status' => AssetStatus::Stored,
        'disk' => 'media',
        'path' => 'youtube/ab/dQw4w9WgXcQ/18.mp4',
    ])->save();

    cache()->clear();
    Queue::fake();

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();

    Queue::assertNothingPushed();
});

it('shares one stored file between tenants', function () {
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();
    cache()->clear();
    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();

    // One download serves every consumer. Nothing tenant-derived is recorded
    // on the row.
    expect(MediaAsset::query()->count())->toBe(1);
});

it('still applies the caller ceilings to a file it already holds', function () {
    // The file is shared between tenants; the limits are not. A warm hit must
    // not quietly hand a tenant something its own configuration forbids.
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    resolveAs(Tenant::factory()->create(), ['max_height' => 360])->assertOk();

    MediaAsset::query()->sole()->forceFill([
        'status' => AssetStatus::Stored,
        'disk' => 'media',
        'path' => 'youtube/ab/dQw4w9WgXcQ/18.mp4',
        'bytes' => 10_000_000,
    ])->save();

    cache()->clear();

    resolveAs(Tenant::factory()->create(['max_bytes' => 1_000_000]), ['max_height' => 360])
        ->assertStatus(413)
        ->assertJsonPath('code', 'too_large');
});

it('serves the url it just handed out', function () {
    // End to end: resolve, run the queued transfer, download from the URL in
    // the response with no headers and no token.
    fakeRunner(FakeYtDlpRunner::withFixture('progressive-only'));

    $url = resolveAs(Tenant::factory()->create(), ['max_height' => 360])
        ->assertOk()
        ->json('media.url');

    $response = $this->get($url)->assertOk();

    expect($response->streamedContent())->toBe('fake-media-bytes');
    expect(Storage::disk('media')->allFiles())->toHaveCount(1);
});
