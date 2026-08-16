<?php

namespace App\Api;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The correlation id for a single logical client action.
 *
 * Clients reuse X-Request-Id across their own retries, which is what makes it
 * usable as a deduplication key: extraction is expensive and running it twice
 * for one user action is pure waste.
 *
 * Stored in Context rather than the container so it propagates into queued jobs
 * and log entries without being threaded through every call signature.
 */
final class RequestId
{
    public const CONTEXT_KEY = 'request_id';

    public const HEADER = 'X-Request-Id';

    public static function set(string $requestId): void
    {
        Context::add(self::CONTEXT_KEY, $requestId);
    }

    public static function current(): string
    {
        $existing = Context::get(self::CONTEXT_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // An error can be rendered before the middleware ran (for example a
        // malformed request rejected during routing). Mint one so the envelope
        // never ships a null request_id.
        $generated = (string) Str::uuid();
        self::set($generated);

        return $generated;
    }

    /**
     * Accept a client-supplied value only if it is a plausible correlation id.
     *
     * Deliberately permissive about format but strict about length and
     * character set: the value becomes part of a cache key, so it must not be
     * able to carry delimiters or unbounded length into Redis.
     */
    public static function isAcceptable(?string $candidate): bool
    {
        return is_string($candidate)
            && $candidate !== ''
            && strlen($candidate) <= 128
            && preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) === 1;
    }
}
