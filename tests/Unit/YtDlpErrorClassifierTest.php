<?php

use App\Api\ErrorCode;
use App\Media\YtDlp\ExtractorReport;
use App\Media\YtDlp\YtDlpErrorClassifier;
use App\Media\YtDlp\YtDlpFailed;

it('classifies real yt-dlp stderr into the right code', function (string $stderr, ErrorCode $expected) {
    $result = (new YtDlpErrorClassifier)->classify(new YtDlpFailed(stderr: $stderr, exitCode: 1));

    expect($result->errorCode)->toBe($expected);
})->with([
    // Captured verbatim from a real run against a nonexistent id.
    'removed video' => ['ERROR: [youtube] AAAAAAAAAAA: Video unavailable', ErrorCode::Unresolvable],
    'private' => ['ERROR: [youtube] abc: Private video. Sign in if you\'ve been granted access to this video', ErrorCode::PrivateOrRestricted],
    'age gated' => ['ERROR: [youtube] abc: Sign in to confirm your age. This video may be inappropriate for some users.', ErrorCode::PrivateOrRestricted],
    'members only' => ['ERROR: [youtube] abc: Join this channel to get access to members-only content', ErrorCode::PrivateOrRestricted],
    'geo blocked' => ['ERROR: [youtube] abc: The uploader has not made this video available in your country', ErrorCode::RegionBlocked],
    'no format' => ['ERROR: [youtube] abc: Requested format is not available', ErrorCode::MediaMissing],
    'dns failure' => ['ERROR: Unable to download webpage: <urlopen error [Errno -2] Name or service not known>', ErrorCode::Offline],
    'upstream 5xx' => ['ERROR: unable to download video data: HTTP Error 503: Service Unavailable', ErrorCode::DownloadFailed],
    'nsig broken' => ['WARNING: [youtube] abc: nsig extraction failed: Some formats may be missing', ErrorCode::ResolverUnavailable],
]);

it('reports a bot check as a retryable resolver failure, never a 403', function () {
    $stderr = "ERROR: [youtube] abc: Sign in to confirm you're not a bot. Use --cookies-from-browser or --cookies";

    $result = (new YtDlpErrorClassifier)->classify(new YtDlpFailed(stderr: $stderr, exitCode: 1));

    // A 403 would tell the client to give up permanently on a video that works
    // fine. This is our infrastructure problem, not the user's permission
    // problem, so it must be retryable.
    expect($result->errorCode)->toBe(ErrorCode::ResolverUnavailable)
        ->and($result->errorCode->httpStatus())->toBe(503)
        ->and($result->errorCode->isRetryable())->toBeTrue();

    expect(ExtractorReport::botChecksThisHour())->toBe(1);
});

it('turns unrecognised stderr into a counted breakage rather than a silent failure', function () {
    // This is the branch that catches next week's YouTube change.
    $result = (new YtDlpErrorClassifier)->classify(
        new YtDlpFailed(stderr: 'ERROR: something nobody has ever seen before', exitCode: 1)
    );

    expect($result->errorCode)->toBe(ErrorCode::ResolverUnavailable)
        ->and($result->errorCode->isRetryable())->toBeTrue();

    expect(ExtractorReport::breakagesThisHour())->toBe(1);
});

it('does not raise a false extractor alarm for an ordinary permanent failure', function () {
    (new YtDlpErrorClassifier)->classify(
        new YtDlpFailed(stderr: 'ERROR: [youtube] abc: Video unavailable', exitCode: 1)
    );

    // A deleted video is not a broken extractor. Alerting on it would bury the
    // signal that matters.
    expect(ExtractorReport::breakagesThisHour())->toBe(0)
        ->and(ExtractorReport::botChecksThisHour())->toBe(0);
});

it('classifies a deadline miss as retryable with a retry hint', function () {
    $result = (new YtDlpErrorClassifier)->classify(YtDlpFailed::timedOut(8000));

    expect($result->errorCode)->toBe(ErrorCode::ResolverUnavailable)
        ->and($result->retryAfter)->toBe(2)
        ->and($result->context['reason'])->toBe('timeout');
});

it('keeps internal detail out of the client-facing message', function () {
    $stderr = 'ERROR: [youtube] abc: Sign in to confirm you\'re not a bot. Use --cookies-from-browser';

    $result = (new YtDlpErrorClassifier)->classify(new YtDlpFailed(stderr: $stderr, exitCode: 1));

    // stderr names our mitigation strategy and binary flags. It belongs in logs,
    // not in a response body a third party reads.
    expect($result->getMessage())->not->toContain('cookies')
        ->and($result->getMessage())->not->toContain('yt-dlp');
});

it('keeps signed cdn urls out of the diagnostics it records', function () {
    // yt-dlp echoes the urls it worked on, and a googlevideo format url carries
    // its own signature in the query string. Logs outlive the url and travel
    // further than the media is meant to.
    $stderr = 'ERROR: unable to download https://rr3---sn-abc.googlevideo.com/videoplayback'
        .'?expire=2051222400&signature=DEADBEEFCAFE&ip=203.0.113.7 : HTTP Error 403';

    $result = (new YtDlpErrorClassifier)->classify(new YtDlpFailed(stderr: $stderr, exitCode: 1));

    $recorded = $result->context['stderr'];

    expect($recorded)->not->toContain('signature=DEADBEEFCAFE')
        ->and($recorded)->not->toContain('expire=')
        ->and($recorded)->not->toContain('203.0.113.7')
        // The host is most of the debugging value, so it survives.
        ->and($recorded)->toContain('rr3---sn-abc.googlevideo.com')
        ->and($recorded)->toContain('HTTP Error 403');
});
