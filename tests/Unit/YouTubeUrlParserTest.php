<?php

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Providers\YouTube\YouTubeUrlParser;

it('reads the video id from every url shape a share sheet produces', function (string $url) {
    expect((new YouTubeUrlParser)->parse($url))->toBe('dQw4w9WgXcQ');
})->with([
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'watch with extra params' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PLabc'],
    'short link' => ['https://youtu.be/dQw4w9WgXcQ'],
    'short link with params' => ['https://youtu.be/dQw4w9WgXcQ?si=xyz'],
    'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'],
    'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
    'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ'],
    'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ'],
    'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
]);

it('claims only youtube hosts', function (string $url, bool $claimed) {
    expect((new YouTubeUrlParser)->claims($url))->toBe($claimed);
})->with([
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', true],
    ['https://youtu.be/dQw4w9WgXcQ', true],
    ['https://www.instagram.com/reel/abc/', false],
    ['https://www.tiktok.com/@user/video/123', false],
    // Must not be fooled by a lookalike host.
    ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ', false],
]);

it('rejects a malformed id as permanently unresolvable', function () {
    try {
        (new YouTubeUrlParser)->parse('https://www.youtube.com/watch?v=tooshort');
        $this->fail('Expected unresolvable.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe(ErrorCode::Unresolvable)
            ->and($e->errorCode->isRetryable())->toBeFalse();
    }
});

it('rejects a youtube url with no video in it', function () {
    expect(fn () => (new YouTubeUrlParser)->parse('https://www.youtube.com/feed/subscriptions'))
        ->toThrow(ApiException::class);
});

it('recognises the shorts url form', function () {
    // Worth preserving: oEmbed reports 16:9 for a vertical video when asked with
    // the /watch form, and the true portrait size with the /shorts form.
    $parser = new YouTubeUrlParser;

    expect($parser->isShortsUrl('https://www.youtube.com/shorts/dQw4w9WgXcQ'))->toBeTrue()
        ->and($parser->isShortsUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeFalse();
});
