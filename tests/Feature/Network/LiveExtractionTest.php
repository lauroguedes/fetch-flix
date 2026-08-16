<?php

use App\Media\Data\MediaRequest;
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

it('returns a url that actually downloads with the headers it returns', function () {
    // The one assertion the fixture suite cannot make: that the headers we pass
    // through are sufficient for the CDN to serve the bytes.
    $provider = app(ProviderRegistry::class)->resolverFor('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    $resolved = $provider->resolve(new MediaRequest(
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        maxHeight: 360,
    ));

    $response = Http::withHeaders($resolved->media->headers)
        ->withOptions(['stream' => true])
        // Range-limited: we are proving the URL serves media, not downloading it.
        ->withHeader('Range', 'bytes=0-1023')
        ->timeout(20)
        ->get($resolved->media->url);

    expect($response->status())->toBeIn([200, 206])
        ->and($response->header('Content-Type'))->toContain('video/');
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
