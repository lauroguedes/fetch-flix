<?php

namespace App\Api;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The correlation id for a single logical client action.
 *
 * Clients reuse X-Request-Id across retries, which is what makes it usable as a
 * deduplication key. Stored in Context rather than the container so it reaches
 * queued jobs and log entries without being threaded through call signatures.
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

        // An error can render before the middleware ran (a malformed request
        // rejected during routing). Mint one so request_id is never null.
        $generated = (string) Str::uuid();
        self::set($generated);

        return $generated;
    }

    /**
     * Permissive about format, strict about length and character set: the value
     * becomes part of a cache key and must not carry delimiters or unbounded
     * length into Redis.
     */
    public static function isAcceptable(?string $candidate): bool
    {
        return is_string($candidate)
            && $candidate !== ''
            && strlen($candidate) <= 128
            && preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) === 1;
    }
}
