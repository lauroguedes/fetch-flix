<?php

use App\Media\Data\AssetStatus;
use App\Media\Storage\AssetStore;
use App\Models\MediaAsset;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config()->set('media.storage.disk', 'media');

    // Waiting is exercised in its own test; everywhere else it would only make
    // the suite slow.
    config()->set('media.storage.wait_ms', 0);
    config()->set('media.storage.poll_ms', 1);
});

function storedAsset(array $attributes = []): MediaAsset
{
    $asset = MediaAsset::factory()->stored()->create($attributes);

    Storage::disk('media')->put($asset->path, 'video-bytes');

    return $asset;
}

function streamUrl(MediaAsset $asset): string
{
    return app(AssetStore::class)->signedUrl($asset->asset_key)['url'];
}

it('serves the stored file with no token at all', function () {
    // The whole point: the caller's IP and headers are irrelevant, which is
    // what the provider's own URL could never offer.
    $response = $this->get(streamUrl(storedAsset()));

    $response->assertOk()
        ->assertHeader('Content-Type', 'video/mp4');

    expect($response->streamedContent())->toBe('video-bytes');
});

it('accepts a link minted under a different scheme and host', function () {
    // The failure this prevents: a queue worker has no request, so it signs
    // with `app.url`, and that envelope is served from the cross-tenant cache
    // to callers arriving over the proxy's https host. While the signature
    // covered scheme and host, every one of those downloads 403'd for the life
    // of the cache entry. It covers only the path and query now.
    $link = streamUrl(storedAsset());

    expect($link)->toStartWith('/api/v1/stream/');

    $this->get('https://served-from-anywhere.test'.$link)->assertOk();
});

it('honours a byte range so players can seek', function () {
    $response = $this->get(streamUrl(storedAsset()), ['Range' => 'bytes=0-4']);

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-4/11');

    expect($response->streamedContent())->toBe('video');
});

it('redirects to the bucket when the file is on a remote disk', function () {
    // On S3 or R2 the media never passes through this application, which is
    // what keeps a video service on one small web node.
    config()->set('filesystems.disks.media.driver', 's3');

    Storage::disk('media')->buildTemporaryUrlsUsing(
        fn (string $path, $expiry) => 'https://bucket.example/'.$path.'?signed=1'
    );

    $asset = storedAsset();

    $response = $this->get(streamUrl($asset))
        ->assertRedirect('https://bucket.example/'.$asset->path.'?signed=1');

    // A cached redirect would hand a later caller an already-expired link.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('serves locally rather than redirecting through itself', function () {
    // A local disk with `serve` enabled can mint temporary URLs too.
    // Redirecting to one would send the client back through this application
    // for a file that is already on this machine.
    Storage::disk('media')->buildTemporaryUrlsUsing(
        fn (string $path, $expiry) => 'https://should-not-be-used.example/'.$path
    );

    $this->get(streamUrl(storedAsset()))->assertOk();
});

it('refuses to proxy every byte when a remote disk cannot pre-sign', function () {
    config()->set('filesystems.disks.media.driver', 's3');

    $asset = storedAsset();

    // Storage::fake always installs a temporary-url callback, and there is no
    // public way to take it back off. Reaching in is the only way to reproduce
    // a genuinely misconfigured remote disk.
    Closure::bind(
        function () {
            $this->temporaryUrlCallback = null;
        },
        Storage::disk('media'),
        FilesystemAdapter::class,
    )();

    $this->getJson(streamUrl($asset))
        ->assertStatus(503)
        ->assertJsonPath('code', 'resolver_unavailable');
});

it('rejects a link that was never signed', function () {
    $asset = storedAsset();

    $this->getJson(route('api.v1.stream', ['asset' => $asset->asset_key]))
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('rejects a tampered link', function () {
    $url = streamUrl(storedAsset());

    $this->getJson($url.'&extra=1')
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('tells an expired link apart from a forged one', function () {
    // Opposite meanings to a client: expired is "resolve again, this works",
    // forged is "stop". Laravel's own signed middleware answers 403 to both.
    $asset = storedAsset();
    config()->set('media.storage.signed_ttl', 60);

    $url = streamUrl($asset);

    $this->travel(2)->hours();

    $this->getJson($url)
        ->assertStatus(410)
        ->assertJsonPath('code', 'expired')
        ->assertJsonPath('retry_after', null);
});

it('asks for a fresh resolve when the asset has been pruned', function () {
    $asset = storedAsset();
    $url = streamUrl($asset);

    $asset->delete();

    $this->getJson($url)
        ->assertStatus(410)
        ->assertJsonPath('code', 'expired');
});

it('self-heals when the row says stored but the file is gone', function () {
    $asset = storedAsset();
    Storage::disk('media')->delete($asset->path);

    $this->getJson(streamUrl($asset))
        ->assertStatus(410)
        ->assertJsonPath('code', 'expired');

    // Left claimable, so the next resolve downloads it again instead of
    // serving an empty response forever.
    expect($asset->fresh()->status)->toBe(AssetStatus::Failed);
});

it('waits for an in-flight download and then serves it', function () {
    config()->set('media.storage.wait_ms', 2000);
    config()->set('media.storage.poll_ms', 10);

    $asset = MediaAsset::factory()->downloading()->create();
    $url = streamUrl($asset);

    // Stand in for the worker finishing while the request is parked.
    $stored = MediaAsset::factory()->stored()->make();
    $asset->forceFill([
        'status' => AssetStatus::Stored,
        'disk' => 'media',
        'path' => $stored->path,
        'mime_type' => 'video/mp4',
    ])->save();

    Storage::disk('media')->put($stored->path, 'video-bytes');

    $this->get($url)->assertOk();
});

it('tells the client to retry when the download outlasts the wait', function () {
    $asset = MediaAsset::factory()->downloading()->create();

    $this->getJson(streamUrl($asset))
        ->assertStatus(503)
        ->assertJsonPath('code', 'resolver_unavailable')
        ->assertHeader('Retry-After', '5');
});

it('reports the failure the download actually hit', function () {
    // Flattening every stored failure into download_failed would have clients
    // retrying a video that will never become available.
    $asset = MediaAsset::factory()->failed()->create(['error_code' => 'private_or_restricted']);

    $this->getJson(streamUrl($asset))
        ->assertStatus(403)
        ->assertJsonPath('code', 'private_or_restricted')
        ->assertJsonPath('retry_after', null);
});

it('keeps a transfer failure retryable', function () {
    $asset = MediaAsset::factory()->failed()->create(['error_code' => 'download_failed']);

    $this->getJson(streamUrl($asset))
        ->assertStatus(502)
        ->assertJsonPath('code', 'download_failed')
        ->assertJsonPath('retry_after', 5);
});

it('records that the file was wanted', function () {
    $asset = storedAsset(['last_accessed_at' => now()->subDays(2)]);

    $this->get(streamUrl($asset))->assertOk();

    expect($asset->fresh()->last_accessed_at->isToday())->toBeTrue();
});

it('rate limits the delivery endpoint', function () {
    // Unauthenticated and, for an asset still downloading, able to hold a PHP
    // worker for the whole wait window. Without a ceiling a few dozen
    // concurrent callers exhaust the pool and take the service down.
    $url = streamUrl(storedAsset());

    for ($i = 0; $i < 120; $i++) {
        $this->get($url)->assertOk();
    }

    $this->get($url)
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');
});
