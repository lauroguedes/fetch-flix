<?php

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;

/*
| The contract every consumer depends on: clients retry 429 and 5xx, and never
| any other 4xx. These tests exist so that a future change cannot quietly make a
| permanent failure look retryable, or a transient one look permanent. Both
| mistakes are invisible in code review and expensive in production.
*/

it('maps every error code to the documented http status', function (ErrorCode $code, int $expected) {
    expect($code->httpStatus())->toBe($expected);
})->with([
    'unresolvable' => [ErrorCode::Unresolvable, 422],
    'private_or_restricted' => [ErrorCode::PrivateOrRestricted, 403],
    'region_blocked' => [ErrorCode::RegionBlocked, 451],
    'too_long' => [ErrorCode::TooLong, 422],
    'too_large' => [ErrorCode::TooLarge, 413],
    'media_missing' => [ErrorCode::MediaMissing, 422],
    'expired' => [ErrorCode::Expired, 410],
    'rate_limited' => [ErrorCode::RateLimited, 429],
    'resolver_unavailable' => [ErrorCode::ResolverUnavailable, 503],
    'offline' => [ErrorCode::Offline, 502],
    'download_failed' => [ErrorCode::DownloadFailed, 502],
    'invalid_request' => [ErrorCode::InvalidRequest, 422],
    'unauthorized' => [ErrorCode::Unauthorized, 403],
]);

it('marks exactly the transient conditions as retryable', function () {
    $retryable = collect(ErrorCode::cases())
        ->filter(fn (ErrorCode $code) => $code->isRetryable())
        ->map(fn (ErrorCode $code) => $code->value)
        ->values()
        ->all();

    expect($retryable)->toEqualCanonicalizing([
        'rate_limited',
        'resolver_unavailable',
        'offline',
        'download_failed',
    ]);
});

it('never marks a permanent condition with a retryable status', function () {
    $permanent = [
        ErrorCode::Unresolvable,
        ErrorCode::PrivateOrRestricted,
        ErrorCode::RegionBlocked,
        ErrorCode::TooLong,
        ErrorCode::TooLarge,
        ErrorCode::MediaMissing,
        ErrorCode::Expired,
        ErrorCode::InvalidRequest,
        ErrorCode::Unauthorized,
    ];

    foreach ($permanent as $code) {
        expect($code->httpStatus())->toBeLessThan(500)
            ->and($code->httpStatus())->not->toBe(429)
            ->and($code->isRetryable())->toBeFalse();
    }
});

it('keeps expired non-retryable so clients re-resolve instead of replaying a dead request id', function () {
    // A 5xx here would make the client retry the same X-Request-Id straight back
    // into the same expired cache entry, and never recover.
    expect(ErrorCode::Expired->httpStatus())->toBe(410)
        ->and(ErrorCode::Expired->isRetryable())->toBeFalse();
});

it('refuses to attach a retry hint to a permanent condition', function () {
    expect(fn () => new ApiException(ErrorCode::TooLong, retryAfter: 30))
        ->toThrow(LogicException::class);
});

it('gives every code a client-safe message', function (ErrorCode $code) {
    expect($code->message())->not->toBeEmpty();
})->with(fn () => array_map(fn (ErrorCode $c) => [$c], ErrorCode::cases()));
