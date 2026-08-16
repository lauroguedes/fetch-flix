<?php

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\YtDlp\FormatSelector;
use App\Media\YtDlp\YtDlpResult;
use Tests\Support\FakeYtDlpRunner;

function ytdlpFixture(string $name): YtDlpResult
{
    return new YtDlpResult(FakeYtDlpRunner::fixture($name));
}

it('never returns an HLS playlist even when it satisfies the requested height', function () {
    // YouTube advertises itags 91-96 as ext=mp4 with both codecs present, but
    // their URLs are .m3u8 playlists. A codec-only filter would return the 720p
    // one here and the client's downloader would choke on a manifest.
    $stream = (new FormatSelector)->select(
        ytdlpFixture('standard'),
        new MediaRequest(url: 'https://youtu.be/dQw4w9WgXcQ', maxHeight: 720),
    );

    expect($stream->url)->not->toContain('.m3u8')
        ->and($stream->container)->toBe('mp4')
        ->and($stream->hasAudio)->toBeTrue()
        ->and($stream->hasVideo)->toBeTrue();
});

it('degrades to the best true progressive stream and says so', function () {
    // itag 22 no longer exists, so a 720p request lands on itag 18 at 360p.
    $stream = (new FormatSelector)->select(
        ytdlpFixture('progressive-only'),
        new MediaRequest(url: 'https://youtu.be/dQw4w9WgXcQ', maxHeight: 720),
    );

    expect($stream->height)->toBe(360)
        ->and($stream->width)->toBe(640)
        ->and($stream->degraded)->toBeTrue()
        ->and($stream->requestedHeight)->toBe(720);
});

it('does not flag degradation when the request is already satisfiable', function () {
    $stream = (new FormatSelector)->select(
        ytdlpFixture('progressive-only'),
        new MediaRequest(url: 'https://youtu.be/dQw4w9WgXcQ', maxHeight: 360),
    );

    expect($stream->height)->toBe(360)
        ->and($stream->degraded)->toBeFalse()
        ->and($stream->requestedHeight)->toBeNull();
});

it('reports media_missing when only DASH and HLS streams exist', function () {
    expect(fn () => (new FormatSelector)->select(
        ytdlpFixture('no-progressive'),
        new MediaRequest(url: 'https://youtu.be/dQw4w9WgXcQ', maxHeight: 720),
    ))->toThrow(
        ApiException::class,
    );

    try {
        (new FormatSelector)->select(
            ytdlpFixture('no-progressive'),
            new MediaRequest(url: 'https://youtu.be/dQw4w9WgXcQ', maxHeight: 720),
        );
    } catch (ApiException $e) {
        // Permanent: no amount of retrying makes a muxed stream appear.
        expect($e->errorCode)->toBe(ErrorCode::MediaMissing)
            ->and($e->errorCode->isRetryable())->toBeFalse();
    }
});

it('reports media_missing when there are no formats at all', function () {
    try {
        (new FormatSelector)->select(
            ytdlpFixture('no-formats'),
            new MediaRequest(url: 'https://youtu.be/dQw4w9WgXcQ'),
        );
        $this->fail('Expected media_missing.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe(ErrorCode::MediaMissing);
    }
});

it('honours a tenant quality floor instead of degrading below it', function () {
    try {
        (new FormatSelector)->select(
            ytdlpFixture('progressive-only'),
            new MediaRequest(url: 'https://youtu.be/x', maxHeight: 720, minProgressiveHeight: 480),
        );
        $this->fail('Expected media_missing.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe(ErrorCode::MediaMissing);
    }
});

it('falls back to filesize_approx when no exact size is reported', function () {
    // Muxed YouTube formats carry only an approximation, and the client uses
    // this to refuse oversized downloads early.
    $stream = (new FormatSelector)->select(
        ytdlpFixture('progressive-only'),
        new MediaRequest(url: 'https://youtu.be/x', maxHeight: 360),
    );

    expect($stream->bytes)->toBeInt()->toBeGreaterThan(0);
});

it('rejects a stream larger than the tenant allows', function () {
    try {
        (new FormatSelector)->select(
            ytdlpFixture('progressive-only'),
            new MediaRequest(url: 'https://youtu.be/x', maxHeight: 360, maxBytes: 1000),
        );
        $this->fail('Expected too_large.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe(ErrorCode::TooLarge)
            ->and($e->errorCode->httpStatus())->toBe(413);
    }
});

it('passes http headers through verbatim', function () {
    $stream = (new FormatSelector)->select(
        ytdlpFixture('progressive-only'),
        new MediaRequest(url: 'https://youtu.be/x', maxHeight: 360),
    );

    $expected = FakeYtDlpRunner::fixture('progressive-only')['formats'];
    $itag18 = collect($expected)->firstWhere('format_id', '18');

    expect($stream->headers)->toBe($itag18['http_headers']);
});

it('strips only Accept-Encoding, which URLSession sets itself', function () {
    $info = FakeYtDlpRunner::fixture('progressive-only');
    foreach ($info['formats'] as $i => $f) {
        if (($f['format_id'] ?? null) === '18') {
            $info['formats'][$i]['http_headers']['Accept-Encoding'] = 'gzip, deflate';
            $info['formats'][$i]['http_headers']['Referer'] = 'https://www.youtube.com/';
        }
    }

    $stream = (new FormatSelector)->select(
        new YtDlpResult($info),
        new MediaRequest(url: 'https://youtu.be/x', maxHeight: 360),
    );

    expect($stream->headers)->not->toHaveKey('Accept-Encoding')
        // A CDN that 403s without a Referer must still get one.
        ->and($stream->headers['Referer'])->toBe('https://www.youtube.com/')
        ->and($stream->headers)->toHaveKey('User-Agent');
});

it('selects an audio-only stream when asked', function () {
    $stream = (new FormatSelector)->select(
        ytdlpFixture('standard'),
        new MediaRequest(url: 'https://youtu.be/x', audioOnly: true),
    );

    expect($stream->hasAudio)->toBeTrue()
        ->and($stream->hasVideo)->toBeFalse()
        ->and($stream->mimeType)->toStartWith('audio/')
        ->and($stream->url)->not->toContain('.m3u8');
});

it('returns the smallest progressive stream when every option exceeds the ceiling', function () {
    // Respects the intent of a ceiling better than erroring, and the client
    // sizes its frame from the truthful dimensions we return either way.
    $info = FakeYtDlpRunner::fixture('progressive-only');
    foreach ($info['formats'] as $i => $f) {
        if (($f['format_id'] ?? null) === '18') {
            $info['formats'][$i]['height'] = 720;
            $info['formats'][$i]['width'] = 1280;
        }
    }

    $stream = (new FormatSelector)->select(
        new YtDlpResult($info),
        new MediaRequest(url: 'https://youtu.be/x', maxHeight: 360),
    );

    expect($stream->height)->toBe(720)
        ->and($stream->degraded)->toBeTrue();
});
