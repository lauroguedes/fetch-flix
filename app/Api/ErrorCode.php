<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/**
 * The error code taxonomy every client of this service branches on. Part of the
 * public contract: a case cannot be renamed or removed without a new version.
 *
 * The invariant it protects: clients retry 429 and 5xx and nothing else, so a
 * permanent condition must never map to a retryable status and a transient one
 * must never map to a 4xx.
 */
enum ErrorCode: string
{
    /** URL parses but nothing can be resolved from it: deleted, unlisted, unknown provider. */
    case Unresolvable = 'unresolvable';

    /** Private, age-gated, or members-only. The requester is not permitted to see it. */
    case PrivateOrRestricted = 'private_or_restricted';

    /** Unavailable in the region this service egresses from. */
    case RegionBlocked = 'region_blocked';

    /** Duration exceeds the caller's max_duration_ms, or the tenant's ceiling. */
    case TooLong = 'too_long';

    /** Reported file size exceeds the tenant's max_bytes. */
    case TooLarge = 'too_large';

    /** Extraction succeeded but produced no stream usable under the requested constraints. */
    case MediaMissing = 'media_missing';

    /** A previously issued stream URL is past its expiry. The caller must re-resolve. */
    case Expired = 'expired';

    /** Tenant burst limit, or tenant/global daily quota, is exhausted. */
    case RateLimited = 'rate_limited';

    /** Extractor is broken, bot-checked, or exceeded its deadline. Transient, ours to fix. */
    case ResolverUnavailable = 'resolver_unavailable';

    /** Network failure reaching the upstream provider. */
    case Offline = 'offline';

    /** Upstream returned a transport error during extraction. */
    case DownloadFailed = 'download_failed';

    /** Request body failed validation. Additive; not in the original client contract. */
    case InvalidRequest = 'invalid_request';

    /** Missing, invalid, or insufficiently scoped token; suspended tenant. Additive. */
    case Unauthorized = 'unauthorized';

    /**
     * The HTTP status this code is rendered with. Two mappings are deliberate:
     *
     * - Expired is 410, not 503: the contract is to re-resolve with a fresh
     *   request id, and a 5xx would retry the same id into the same expired
     *   cache entry.
     * - RateLimited is 429 even for an exhausted daily API quota, which is a
     *   retryable wait rather than a fault. Callers tell the two apart via the
     *   X-Quota-Scope header.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Unresolvable, self::TooLong, self::MediaMissing, self::InvalidRequest => Response::HTTP_UNPROCESSABLE_ENTITY,
            self::PrivateOrRestricted, self::Unauthorized => Response::HTTP_FORBIDDEN,
            self::RegionBlocked => Response::HTTP_UNAVAILABLE_FOR_LEGAL_REASONS,
            self::TooLarge => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            self::Expired => Response::HTTP_GONE,
            self::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,
            self::ResolverUnavailable => Response::HTTP_SERVICE_UNAVAILABLE,
            self::Offline, self::DownloadFailed => Response::HTTP_BAD_GATEWAY,
        };
    }

    /**
     * Derived from the status rather than listed separately, so the two cannot
     * drift apart.
     */
    public function isRetryable(): bool
    {
        $status = $this->httpStatus();

        return $status === Response::HTTP_TOO_MANY_REQUESTS || $status >= 500;
    }

    /**
     * Default client-facing message, free of internal detail: stderr, binary
     * versions and upstream URLs never reach a consumer.
     */
    public function message(): string
    {
        return match ($this) {
            self::Unresolvable => 'This media could not be resolved.',
            self::PrivateOrRestricted => 'This media is private or restricted.',
            self::RegionBlocked => 'This media is not available in this region.',
            self::TooLong => 'This media is longer than the requested maximum duration.',
            self::TooLarge => 'This media is larger than the permitted maximum size.',
            self::MediaMissing => 'No usable stream is available for this media.',
            self::Expired => 'This stream URL has expired. Please resolve again.',
            self::RateLimited => 'Rate limit or quota exceeded. Please retry later.',
            self::ResolverUnavailable => 'The resolver is temporarily unavailable.',
            self::Offline => 'The provider could not be reached.',
            self::DownloadFailed => 'The provider returned an error while resolving.',
            self::InvalidRequest => 'The request payload is invalid.',
            self::Unauthorized => 'Authentication failed or the token lacks the required scope.',
        };
    }
}
