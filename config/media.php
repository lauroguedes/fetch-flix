<?php

use App\Api\ErrorCode;
use App\Media\Providers\YouTube\YouTubeProvider;

return [

    // Declared rather than discovered, so a deployment enables only what it is
    // willing to operate.
    'providers' => [
        'youtube' => [
            'enabled' => env('MEDIA_YOUTUBE_ENABLED', true),
            'class' => YouTubeProvider::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Resolved media is downloaded, kept on our own disk, and served behind a
    | signed URL of ours. Provider URLs are bound to the IP that extracted them:
    | a server resolves, a phone on another network downloads, and the CDN
    | answers 403. Only serving the bytes ourselves fixes that.
    |
    */

    'storage' => [
        'disk' => env('MEDIA_DISK', 'media'),

        // Deployment-wide ceiling, on top of any per-tenant max_bytes. Refused
        // before anything is downloaded.
        'max_bytes' => (int) env('MEDIA_MAX_BYTES', 524288000),

        // How long a signed delivery URL stays valid. Unlike the provider's
        // expiry, this one is ours to choose and ours to reissue.
        'signed_ttl' => (int) env('MEDIA_SIGNED_TTL', 21600),

        // Lifetime of the pre-signed bucket URL we redirect to. Minted per
        // download, never cached.
        'presigned_ttl' => (int) env('MEDIA_PRESIGNED_TTL', 900),

        // A transfer, not a metadata call: not bound by the 12s resolve
        // ceiling. Runs on a queue.
        'download_timeout' => (int) env('MEDIA_DOWNLOAD_TIMEOUT', 300),

        // A worker that dies mid-download leaves the row claimed. Without a
        // reclaim window one crash strands that video forever.
        'claim_stale_after' => (int) env('MEDIA_CLAIM_STALE_AFTER', 900),
        'max_attempts' => (int) env('MEDIA_DOWNLOAD_ATTEMPTS', 3),

        // How long delivery holds a connection waiting for an in-flight
        // download. Only hit by the first request for a given video; kept short
        // because each waiting request occupies a PHP worker.
        'wait_ms' => (int) env('MEDIA_STREAM_WAIT_MS', 20000),
        'poll_ms' => (int) env('MEDIA_STREAM_POLL_MS', 250),

        // Downloads land here first and are streamed to the media disk after.
        'tmp_dir' => env('MEDIA_TMP_DIR', storage_path('app/media-tmp')),

        // Assets nobody has asked for in this many days are collected by
        // media:prune. Storage costs money every month.
        'retention_days' => (int) env('MEDIA_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    |
    | The client calls /resolve synchronously with a 4s connect and 12s total
    | timeout. Everything here keeps us inside that ceiling, and fails fast
    | rather than hanging when it cannot.
    |
    */

    'resolve' => [
        // Hard ceiling on the yt-dlp process, leaving ~3.7s of headroom against
        // the client's 12s budget.
        'process_timeout_ms' => env('MEDIA_PROCESS_TIMEOUT_MS', 8000),

        // How long a resolve envelope is replayed for a repeated X-Request-Id.
        'dedupe_ttl' => env('MEDIA_DEDUPE_TTL', 300),

        // Single-flight lock that stops two callers extracting the same video.
        'lock_ttl' => 15,
        'lock_wait' => 3,

        // Subtracted from every expires_at, so a client that starts its
        // download shortly after resolving is not handed a dying URL.
        'expiry_safety_margin' => 120,

        // Used when a provider gives no parseable expiry of its own.
        'default_expiry' => 3600,

        // URLSession sets its own encoding and a conflicting value corrupts the
        // body. Every other header passes through verbatim: some CDNs 403
        // without an exact Referer.
        'stripped_headers' => ['accept-encoding'],
    ],

    /*
    |--------------------------------------------------------------------------
    | yt-dlp
    |--------------------------------------------------------------------------
    |
    | yt-dlp breaks whenever YouTube changes, often weekly. It is pinned, updated
    | on a schedule behind a self-test, and rolled back when that self-test fails.
    |
    */

    'yt_dlp' => [
        'binary' => env('YT_DLP_BINARY', 'yt-dlp'),

        // Extra PATH entries for the extractor process, colon separated.
        //
        // Solving YouTube's `n` challenge needs a JavaScript runtime (Deno) on
        // PATH. Without one the binary still exits zero and silently drops every
        // format needing a PO token, so a video that resolves from a terminal
        // returns `media_missing` under PHP-FPM's narrower PATH.
        //
        // The directory of an absolute `binary` is always included.
        'path' => env('YT_DLP_PATH'),

        // Nightly, not stable: stable lags YouTube changes by days.
        'channel' => env('YT_DLP_CHANNEL', 'nightly'),

        // Caching the player JS across invocations is the difference between a
        // ~1-2s warm extraction and a ~5-10s cold one. Must be shared, writable
        // and persistent.
        'cache_dir' => env('YT_DLP_CACHE_DIR', storage_path('app/yt-dlp-cache')),

        // Pinned: the unpinned default does not currently work. Volatile by
        // nature, never change blind. Measured against yt-dlp 2026.07.04 on
        // 2026-08-22, downloading the control video and dQw4w9WgXcQ:
        //
        //   (default -> android_vr)  extraction OK, download 403
        //   web, web_safari, ios     "No video formats found"
        //   tv, web_embedded         progressive https formats dropped entirely
        //   mweb                     itag 18 progressive, downloads
        //   tv_simply                itag 18 progressive, downloads
        //
        // Two clients so one breaking degrades to the other instead of to an
        // outage. Note android_vr's failure mode: extraction *succeeds* and only
        // the transfer 403s, which is why yt-dlp:selftest also transfers.
        //
        // Re-validate with the `network` test group before changing this.
        'player_clients' => env('YT_DLP_PLAYER_CLIENTS', 'mweb,tv_simply'),

        'proxy' => env('YT_DLP_PROXY'),

        // Control video for the self-test, never returned to a client. "Me at
        // the zoo": the first YouTube upload, 19s, about as unlikely to be
        // deleted as anything on the platform. The previous choice (yt-dlp's own
        // BaW_jenozKc) was removed, which made the self-test cry wolf forever.
        'selftest_url' => env('YT_DLP_SELFTEST_URL', 'https://www.youtube.com/watch?v=jNQXAC9IVRw'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error classification
    |--------------------------------------------------------------------------
    |
    | yt-dlp emits no structured errors, so stderr is matched against this
    | ordered table and the first match wins. Unrecognised stderr becomes a
    | retryable ResolverUnavailable and is reported as a breakage, so next week's
    | YouTube change surfaces as one alert instead of a wave of silent 500s.
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

        // A cached superset, filtered by exact duration in PHP, so one page
        // serves every max_duration_ms variant across every tenant.
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
    | renders every portrait clip into a landscape box, so the chain always
    | terminates in a default and never returns null.
    |
    */

    'dimensions' => [
        // oEmbed costs zero API quota and is the only free source of aspect
        // ratio. Verified against live YouTube on 2026-08-16:
        //
        //   9FDVvWR91ww  /watch?v= -> 200x113 (1.77)   /shorts/ -> 113x200 (0.565)
        //   OzK3PNF0WqU  /watch?v= -> 200x113 (1.77)   /shorts/ -> 200x113 (1.77)
        //
        // So the /shorts/ form is required (the /watch form reports 16:9 even
        // for vertical video), and it does NOT force portrait: it told the truth
        // about OzK3PNF0WqU, a landscape video surfacing in a shorts search. Its
        // answer is trusted as returned; a "short duration therefore 9:16"
        // heuristic may only run when oEmbed fails outright.
        //
        // The returned size is the embed player box, not the encode, so only the
        // ratio is meaningful.
        'oembed_endpoint' => 'https://www.youtube.com/oembed',
        'oembed_timeout' => 2,

        // Below this duration, try the /shorts/ oEmbed form first.
        'shorts_duration_threshold_ms' => 180000,

        'default' => ['width' => 1080, 'height' => 1920],
    ],

];
