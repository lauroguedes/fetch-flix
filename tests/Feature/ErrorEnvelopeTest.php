<?php

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;

/*
| The error envelope is the only shape consumers know how to parse. Laravel's
| default validation and auth bodies must never reach them.
*/

beforeEach(function () {
    Route::middleware('request-id')->post('/api/v1/__test/throw', function () {
        throw ApiException::tooLong();
    });

    Route::middleware('request-id')->post('/api/v1/__test/retryable', function () {
        throw ApiException::rateLimited(retryAfter: 42, scope: 'tenant');
    });

    Route::middleware('request-id')->post('/api/v1/__test/boom', function () {
        throw new RuntimeException('something entirely unexpected');
    });

    Route::middleware('request-id')->post('/api/v1/__test/validate', function () {
        request()->validate(['url' => 'required|url']);
    });
});

it('renders the full envelope on a permanent failure', function () {
    $this->postJson('/api/v1/__test/throw')
        ->assertStatus(422)
        ->assertExactJsonStructure(['status', 'code', 'message', 'retry_after', 'request_id'])
        ->assertJson([
            'status' => 'error',
            'code' => 'too_long',
            'retry_after' => null,
        ])
        ->assertHeaderMissing('Retry-After');
});

it('sets both the retry_after field and the Retry-After header on retryable failures', function () {
    $response = $this->postJson('/api/v1/__test/retryable')
        ->assertStatus(429)
        ->assertJson([
            'status' => 'error',
            'code' => 'rate_limited',
            'retry_after' => 42,
        ]);

    expect($response->headers->get('Retry-After'))->toBe('42')
        // How a consumer tells "I spent my allowance" from "the project is out today".
        ->and($response->headers->get('X-Quota-Scope'))->toBe('tenant');
});

it('turns an unexpected exception into a retryable 503, never a 500', function () {
    // An unhandled failure here is nearly always a broken extractor or a
    // transient upstream change. A 500 would tell the client to give up
    // permanently on a request that will succeed on retry.
    $this->postJson('/api/v1/__test/boom')
        ->assertStatus(503)
        ->assertJson([
            'status' => 'error',
            'code' => 'resolver_unavailable',
        ])
        ->assertJsonPath('retry_after', fn ($value) => is_int($value));
});

it('does not re-map a response that was already built', function () {
    // Laravel carries rate-limiter and abort() responses as
    // HttpResponseException. Treating one as an unhandled error discarded a
    // correct 429 into a generic 503, which told the client to retry a
    // permanent-shaped failure for the wrong reason.
    Route::middleware('request-id')->post('/api/v1/__test/prebuilt', function () {
        throw new HttpResponseException(response()->json(['status' => 'error', 'code' => 'rate_limited'], 429));
    });

    $this->postJson('/api/v1/__test/prebuilt')
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');
});

it('renders validation failures in our envelope rather than laravel default', function () {
    $this->postJson('/api/v1/__test/validate', ['url' => 'not-a-url'])
        ->assertStatus(422)
        ->assertJson(['status' => 'error', 'code' => 'invalid_request'])
        // Laravel's default shape would put an "errors" bag here.
        ->assertJsonMissingPath('errors');
});

it('echoes a supplied request id and reuses it in the envelope', function () {
    $requestId = 'client-supplied-abc123';

    $response = $this->postJson('/api/v1/__test/throw', [], [
        'X-Request-Id' => $requestId,
    ]);

    $response->assertJsonPath('request_id', $requestId);
    expect($response->headers->get('X-Request-Id'))->toBe($requestId);
});

it('mints a request id rather than rejecting a malformed one', function () {
    // The header is a debugging and dedupe aid. Failing a media request over its
    // formatting would turn a client-side nicety into an outage.
    $response = $this->postJson('/api/v1/__test/throw', [], [
        'X-Request-Id' => str_repeat('x', 500),
    ]);

    $response->assertStatus(422);
    expect($response->json('request_id'))->not->toBe(str_repeat('x', 500))
        ->and($response->json('request_id'))->not->toBeEmpty();
});

it('returns the contracted envelope for an unknown endpoint', function () {
    $this->postJson('/api/v1/does-not-exist')
        ->assertStatus(422)
        ->assertJson(['status' => 'error', 'code' => 'unresolvable']);
});

it('covers every error code with a status the client handles correctly', function (ErrorCode $code) {
    Route::middleware('request-id')->post('/api/v1/__test/code', function () use ($code) {
        throw new ApiException($code, retryAfter: $code->isRetryable() ? 5 : null);
    });

    $response = $this->postJson('/api/v1/__test/code');

    $response->assertStatus($code->httpStatus())
        ->assertJsonPath('code', $code->value);

    $retriedByClient = $response->status() === 429 || $response->status() >= 500;

    expect($retriedByClient)->toBe($code->isRetryable());
})->with(fn () => array_map(fn (ErrorCode $c) => [$c], ErrorCode::cases()));
