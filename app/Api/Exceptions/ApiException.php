<?php

namespace App\Api\Exceptions;

use App\Api\ErrorCode;
use App\Api\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The only exception type that renders as a client-facing API error, so the
 * envelope has exactly one implementation. Anything else that escapes becomes a
 * ResolverUnavailable via the handler, never a raw 500 with a stack trace.
 *
 * The named constructors below all take an optional `$context` of internal
 * diagnostics, which is logged and never serialized to the client.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        // Named errorCode, not code: Exception already declares a non-readonly
        // int $code and redeclaring it is a fatal error.
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly ?int $retryAfter = null,
        /** @var array<string, string> */
        public readonly array $headers = [],
        /** @var array<string, mixed> Internal diagnostics for logging only; never serialized to the client. */
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode->message(), previous: $previous);

        if ($retryAfter !== null && ! $errorCode->isRetryable()) {
            throw new LogicException(
                "Error code [{$errorCode->value}] is not retryable but was given a retry_after value. ".
                'Returning a retry hint on a permanent condition makes clients retry forever.'
            );
        }
    }

    /** @param array<string, mixed> $context */
    public static function unresolvable(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::Unresolvable, $message, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function privateOrRestricted(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::PrivateOrRestricted, $message, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function regionBlocked(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::RegionBlocked, $message, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function tooLong(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::TooLong, $message, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function tooLarge(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::TooLarge, $message, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function mediaMissing(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::MediaMissing, $message, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function expired(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::Expired, $message, context: $context);
    }

    /**
     * @param  string|null  $scope  'tenant' or 'global', surfaced as
     *                              X-Quota-Scope so a consumer can tell "I spent
     *                              my allowance" from "the project is out today"
     */
    public static function rateLimited(int $retryAfter, ?string $scope = null, ?string $message = null): self
    {
        return new self(
            ErrorCode::RateLimited,
            $message,
            retryAfter: $retryAfter,
            headers: $scope !== null ? ['X-Quota-Scope' => $scope] : [],
        );
    }

    /** @param array<string, mixed> $context */
    public static function resolverUnavailable(int $retryAfter = 2, ?string $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self(ErrorCode::ResolverUnavailable, $message, retryAfter: $retryAfter, context: $context, previous: $previous);
    }

    /** @param array<string, mixed> $context */
    public static function offline(int $retryAfter = 5, ?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::Offline, $message, retryAfter: $retryAfter, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function downloadFailed(int $retryAfter = 5, ?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::DownloadFailed, $message, retryAfter: $retryAfter, context: $context);
    }

    /** @param array<string, mixed> $context */
    public static function invalidRequest(?string $message = null, array $context = []): self
    {
        return new self(ErrorCode::InvalidRequest, $message, context: $context);
    }

    public static function unauthorized(?string $message = null): self
    {
        return new self(ErrorCode::Unauthorized, $message);
    }

    /**
     * Render the shared error envelope. The shape is fixed by the client
     * contract and every key is always present: retry_after is null rather than
     * omitted on permanent conditions.
     */
    public function toResponse(Request $request): JsonResponse
    {
        $headers = $this->headers;

        if ($this->retryAfter !== null) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return new JsonResponse([
            'status' => 'error',
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'retry_after' => $this->retryAfter,
            'request_id' => RequestId::current(),
        ], $this->errorCode->httpStatus(), $headers);
    }
}
