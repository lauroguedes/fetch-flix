<?php

use App\Media\YtDlp\StreamExpiry;
use Illuminate\Support\Carbon;

it('reads the expiry from a googlevideo url query parameter', function () {
    $url = 'https://rr2---sn-8vq54voxhau.googlevideo.com/videoplayback?expire=2051222400&ei=abc&mime=video%2Fmp4';

    expect(StreamExpiry::parse($url))->toBe(2051222400)
        ->and(StreamExpiry::from($url)->toIso8601ZuluString())->toBe('2035-01-01T00:00:00Z');
});

it('reads the expiry from a path segment when it is not in the query', function () {
    $url = 'https://rr2.googlevideo.com/videoplayback/expire/2051222400/ei/abc/mime/video%2Fmp4';

    expect(StreamExpiry::parse($url))->toBe(2051222400);
});

it('falls back to a configured lifetime when no expiry is present', function () {
    Carbon::setTestNow('2026-08-16T12:00:00Z');

    $expiry = StreamExpiry::from('https://cdn.example.com/video.mp4');

    expect($expiry->toIso8601ZuluString())->toBe('2026-08-16T13:00:00Z');

    Carbon::setTestNow();
});

it('pulls the advertised expiry back by the safety margin', function () {
    // A client that resolves at T and starts downloading at T+90 must not be
    // handed a URL that dies mid-transfer.
    $real = Carbon::parse('2026-08-16T12:00:00Z');

    expect(StreamExpiry::withSafetyMargin($real)->toIso8601ZuluString())
        ->toBe('2026-08-16T11:58:00Z');
});

it('does not mutate the expiry it is given', function () {
    $real = Carbon::parse('2026-08-16T12:00:00Z');
    StreamExpiry::withSafetyMargin($real);

    expect($real->toIso8601ZuluString())->toBe('2026-08-16T12:00:00Z');
});

it('returns null rather than guessing when a url carries no expiry', function () {
    expect(StreamExpiry::parse('https://cdn.example.com/plain.mp4'))->toBeNull();
});
