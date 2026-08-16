<?php

use App\Api\ErrorCode;
use App\Media\Providers\YouTube\YouTubeProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Providers are declared here rather than discovered, so a deployment can
    | enable only what it is willing to operate. The registry is built from this
    | list and exposes providers by capability, never by name.
    |
    */

    'providers' => [
        'youtube' => [
            'enabled' => env('MEDIA_YOUTUBE_ENABLED', true),
            'class' => YouTubeProvider::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    |
    | The client calls /resolve synchronously from a blocking runloop with a 4s
    | connect and 12s total timeout. Everything here exists to keep us inside
    | that ceiling with headroom, and to fail fast rather than hang when we
    | cannot.
    |
    */

    'resolve' => [
        // Hard ceiling on the yt-dlp process. Chosen to leave ~3.7s of headroom
        // against the client's 12s budget after middleware and serialization.
        'process_timeout_ms' => env('MEDIA_PROCESS_TIMEOUT_MS', 8000),

        // How long a rendered resolve envelope is replayed for a repeated
        // X-Request-Id. Capped well below stream expiry.
        'dedupe_ttl' => env('MEDIA_DEDUPE_TTL', 300),

        // Single-flight: how long to hold, and how long to wait for, the lock
        // that stops two callers extracting the same video concurrently.
        'lock_ttl' => 15,
        'lock_wait' => 3,

        // Subtracted from every expires_at before it is returned, so a client
        // that starts its download shortly after resolving is not handed a URL
        // that dies mid-transfer.
        'expiry_safety_margin' => 120,

        // Used when a provider gives no parseable expiry of its own.
        'default_expiry' => 3600,

        // URLSession sets its own encoding; a conflicting value corrupts the
        // body. Every other header is passed through verbatim because some CDNs
        // 403 without an exact Referer.
        'stripped_headers' => ['accept-encoding'],
    ],

    /*
    |--------------------------------------------------------------------------
    | yt-dlp
    |--------------------------------------------------------------------------
    |
    | yt-dlp breaks whenever YouTube changes, often weekly. It is pinned, updated
    | on a schedule behind a self-test, and rolled back automatically when that
    | self-test fails.
    |
    */

    'yt_dlp' => [
        'binary' => env('YT_DLP_BINARY', 'yt-dlp'),

        // Nightly, not stable: stable lags YouTube changes by days.
        'channel' => env('YT_DLP_CHANNEL', 'nightly'),

        // Keeping the player JS cached across invocations is the difference
        // between a ~1-2s warm extraction and a ~5-10s cold one. It must be a
        // shared, writable, persistent path.
        'cache_dir' => env('YT_DLP_CACHE_DIR', storage_path('app/yt-dlp-cache')),

        // Empty by default, so yt-dlp picks its own clients.
        //
        // Overriding this is a documented mitigation for datacenter bot checks,
        // but it is NOT free and must never be enabled blind. Verified against
        // yt-dlp 2026.07.04: `player_client=tv,web_embedded` makes YouTube drop
        // the progressive https formats entirely ("Some tv client https formats
        // have been skipped as they are missing a URL. YouTube may have enabled
        // the SABR-only streaming experiment"), and whatever URL survives
        // returns 403 on download. Extraction still "succeeds", so the failure
        // surfaces to the user as a broken download rather than a resolve error.
        //
        // Re-validate with the `network` test group before shipping any value here.
        'player_clients' => env('YT_DLP_PLAYER_CLIENTS'),

        'proxy' => env('YT_DLP_PROXY'),

        // A stable, uncontroversial video used only to prove the extractor still
        // works. Never returned to a client.
        'selftest_url' => env('YT_DLP_SELFTEST_URL', 'https://www.youtube.com/watch?v=BaW_jenozKc'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error classification
    |--------------------------------------------------------------------------
    |
    | yt-dlp emits no structured errors, so stderr is matched against this
    | ordered table. Order matters: the first match wins.
    |
    | The default case is the important one. Unrecognised stderr becomes a
    | retryable ResolverUnavailable and raises an ExtractorBroken event, so next
    | week's YouTube change surfaces as one alert instead of a wave of silent
    | 500s.
    |
    */

    'error_patterns' => [
        ['pattern' => '/Sign in to confirm you.?re not a bot/i', 'code' => ErrorCode::ResolverUnavailable, 'bot_check' => true],
        ['pattern' => '/Private video|Sign in to confirm your age|members[- ]only|join this channel/i', 'code' => ErrorCode::PrivateOrRestricted],
        ['pattern' => '/not available in your country|blocked it on copyright grounds|uploader has not made this video available/i', 'code' => ErrorCode::RegionBlocked],
        ['pattern' => '/Video unavailable|has been removed|account associated with this video has been terminated|Incomplete YouTube ID/i', 'code' => ErrorCode::Unresolvable],
        ['pattern' => '/Requested format is not available|No video formats found/i', 'code' => ErrorCode::MediaMissing],
        ['pattern' => '/timed out|Connection refused|Name or service not known|Temporary failure in name resolution/i', 'code' => ErrorCode::Offline],
        ['pattern' => '/HTTP Error 5\d\d/i', 'code' => ErrorCode::DownloadFailed],
        ['pattern' => '/nsig extraction failed|Unable to extract|Signature extraction failed|player response/i', 'code' => ErrorCode::ResolverUnavailable, 'extractor_broken' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | search.list costs 100 units against a 10,000/day quota shared by every
    | consumer of this service. Caching is the feature, not an optimisation.
    |
    */

    'discovery' => [
        'youtube_api_key' => env('YOUTUBE_API_KEY'),

        // Fresh for 6h, then served stale while refreshed in the background for
        // up to 24h. No caller ever waits on a 100-unit call.
        'cache_fresh' => env('MEDIA_DISCOVER_FRESH', 21600),
        'cache_stale' => env('MEDIA_DISCOVER_STALE', 86400),

        // We cache a superset and filter by exact duration in PHP, so one cached
        // page serves every max_duration_ms variant across every tenant.
        'page_size' => 50,
        'default_max_duration_ms' => 180000,
    ],

    'quota' => [
        'daily_limit' => env('YOUTUBE_DAILY_QUOTA', 10000),

        // Held back for scheduled category warming so live search cannot starve
        // it, and vice versa.
        'reserved_for_warming' => env('YOUTUBE_QUOTA_RESERVED', 4000),

        'costs' => [
            'search.list' => 100,
            'videos.list' => 1,
        ],

        // The Data API resets at midnight Pacific. Not UTC, not the app timezone.
        'reset_timezone' => 'America/Los_Angeles',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dimensions
    |--------------------------------------------------------------------------
    |
    | The Data API returns no width or height, but consumers size a player frame
    | before anything loads and short-form video is 9:16. A missing dimension
    | renders every portrait clip into a landscape box, so these values are
    | never null: the chain always terminates in a default.
    |
    */

    'dimensions' => [
        // oEmbed costs zero API quota. It must be queried with the /shorts/ URL
        // form for vertical video: the /watch?v= form reports 16:9 regardless.
        'oembed_endpoint' => 'https://www.youtube.com/oembed',
        'oembed_timeout' => 2,

        // Below this duration, try the /shorts/ oEmbed form first.
        'shorts_duration_threshold_ms' => 180000,

        // How long the discover path will wait for exact dimensions before
        // shipping defaults and backfilling in the background.
        'inline_budget_ms' => 2500,

        'default' => ['width' => 1080, 'height' => 1920],
    ],

];
