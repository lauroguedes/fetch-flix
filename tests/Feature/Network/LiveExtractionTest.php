<?php

use App\Media\Data\MediaRequest;
use App\Media\Data\VideoCard;
use App\Media\Discovery\DimensionResolver;
use App\Media\ProviderRegistry;
use App\Media\YtDlp\ProcessYtDlpRunner;
use App\Media\YtDlp\StreamExpiry;
use Illuminate\Support\Facades\Http;

/*
| These hit YouTube for real and are excluded from the default suite.
|
| They verify the things fixtures structurally cannot: that the pinned yt-dlp
| binary still works against the live site, that the flags we pass are still
| valid, and that a URL we hand a client actually downloads with the headers we
| hand alongside it.
|
| Run deliberately after a yt-dlp update: php artisan test --group=network
*/

pest()->group('network');

it('returns a url that actually downloads with the headers it returns', function () {
    // The one assertion the fixture suite cannot make: that the headers we pass
    // through are sufficient for the CDN to serve the bytes.
    //
    // Its own video, and first in the file: see the note below on why order
    // matters here.
    $url = 'https://www.youtube.com/watch?v=jNQXAC9IVRw';
    $provider = app(ProviderRegistry::class)->resolverFor($url);

    $resolved = $provider->resolve(new MediaRequest(url: $url, maxHeight: 360));

    $fetch = fn () => Http::withHeaders($resolved->media->headers)
        ->withOptions(['stream' => true])
        // Range-limited: we are proving the URL serves media, not downloading it.
        ->withHeader('Range', 'bytes=0-1023')
        ->timeout(20)
        ->get($resolved->media->url);

    $response = $fetch();

    // Kept deliberately first in this file. Verified 2026-08-16: if other
    // extractions run before it, this download returns 403 every time, and a
    // delayed retry does not clear it. Issued googlevideo URLs are invalidated
    // once the same source has made further player requests, so a URL must be
    // used promptly rather than held while other work happens.
    //
    // The retry below therefore only covers ordinary transient throttling. A
    // genuine "our headers are insufficient" failure still fails both attempts,
    // which is what this test exists to catch.
    if ($response->status() === 403) {
        usleep(3_000_000);
        $response = $fetch();
    }

    expect($response->status())->toBeIn([200, 206])
        ->and($response->header('Content-Type'))->toContain('video/');
});

it('extracts a real video within the synchronous budget', function () {
    $result = (new ProcessYtDlpRunner)->extract('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($result->id())->toBe('dQw4w9WgXcQ')
        ->and($result->durationMs())->toBeGreaterThan(0)
        ->and($result->formats())->not->toBeEmpty()
        // The whole synchronous design rests on this staying true.
        ->and($result->elapsedMs)->toBeLessThan((int) config('media.resolve.process_timeout_ms'));
});

it('still finds a genuinely progressive stream on the live site', function () {
    // If this starts failing, YouTube has removed the last muxed format and the
    // degradation strategy no longer has anything to degrade to. That is a
    // product decision, not a bug fix, so it should fail loudly here first.
    $provider = app(ProviderRegistry::class)->resolverFor('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    $resolved = $provider->resolve(new MediaRequest(
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        maxHeight: 720,
    ));

    expect($resolved->media->hasAudio)->toBeTrue()
        ->and($resolved->media->hasVideo)->toBeTrue()
        ->and($resolved->media->url)->not->toContain('.m3u8')
        ->and($resolved->media->height)->toBeGreaterThan(0);
});

it('reports an expiry in the future that we parsed rather than guessed', function () {
    $provider = app(ProviderRegistry::class)->resolverFor('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    $resolved = $provider->resolve(new MediaRequest(
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        maxHeight: 360,
    ));

    expect(StreamExpiry::parse($resolved->media->url))->not->toBeNull()
        ->and($resolved->media->expiresAt->isFuture())->toBeTrue();
});

it('reads real aspect ratios from live oembed', function () {
    /** @var DimensionResolver $resolver */
    $resolver = app(DimensionResolver::class);

    $out = $resolver->hydrate([
        // A genuine vertical Short.
        new VideoCard('9FDVvWR91ww', 'short', 'c', 31000, null, 0, 0),
        // Appears in shorts feeds but is actually landscape.
        new VideoCard('OzK3PNF0WqU', 'wide', 'c', 80000, null, 0, 0),
    ]);

    expect($out[0]->width)->toBe(1080)->and($out[0]->height)->toBe(1920)
        ->and($out[1]->width)->toBe(1280)->and($out[1]->height)->toBe(720);
});
