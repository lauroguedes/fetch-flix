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
    | Delivery
    |--------------------------------------------------------------------------
    |
    | How a resolved stream reaches the client.
    |
    |   storage      Download the file, keep it on our own disk, and hand back a
    |                signed URL of ours. Chosen because provider URLs are bound
    |                to the IP that extracted them: a server resolves, a phone
    |                on another network downloads, and the CDN answers 403. No
    |                header or retry fixes that, only serving the bytes
    |                ourselves does.
    |
    |   passthrough  Hand back the provider's own URL, as originally designed.
    |                Kept because it is the escape hatch if storage breaks, and
    |                because it costs nothing to keep working.
    |
    | Note this is a real change in posture, not just plumbing: under `storage`
    | this service hosts and redistributes the media rather than merely locating
    | it. That is a business decision, taken deliberately, and the retention
    | settings below exist partly because of it.
    |
    */

    'delivery' => env('MEDIA_DELIVERY', 'storage'),

    'storage' => [
        'disk' => env('MEDIA_DISK', 'media'),

        // Refused before anything is downloaded, on top of any per-tenant
        // max_bytes. A tenant with no ceiling of its own must still not be able
        // to fill the bucket with one request.
        'max_bytes' => (int) env('MEDIA_MAX_BYTES', 524288000),

        // How long a signed delivery URL stays valid. This is what `expires_at`
        // reports, and unlike the provider's expiry it is ours to choose and
        // ours to reissue.
        'signed_ttl' => (int) env('MEDIA_SIGNED_TTL', 21600),

        // Lifetime of the pre-signed bucket URL we redirect to. Short, because
        // it is handed out one download at a time and never cached.
        'presigned_ttl' => (int) env('MEDIA_PRESIGNED_TTL', 900),

        // The download is a file transfer, not a metadata extraction, and is
        // not bound by the client's 12s resolve ceiling. It runs on a queue.
        'download_timeout' => (int) env('MEDIA_DOWNLOAD_TIMEOUT', 300),

        // A worker that dies mid-download leaves the row claimed. After this it
        // may be reclaimed, otherwise one crash strands that video forever.
        'claim_stale_after' => (int) env('MEDIA_CLAIM_STALE_AFTER', 900),
        'max_attempts' => (int) env('MEDIA_DOWNLOAD_ATTEMPTS', 3),

        // How long the delivery endpoint will hold a connection waiting for an
        // in-flight download before telling the client to retry. Only ever hit
        // on the first request for a given video; every later one is served
        // from the disk. Kept short because each waiting request occupies a
        // PHP worker.
        'wait_ms' => (int) env('MEDIA_STREAM_WAIT_MS', 20000),
        'poll_ms' => (int) env('MEDIA_STREAM_POLL_MS', 250),

        // Downloads land here first, on local disk, and are streamed to the
        // media disk afterwards. Needs room for the largest concurrent set of
        // downloads, not for the library.
        'tmp_dir' => env('MEDIA_TMP_DIR', storage_path('app/media-tmp')),

        // Storage costs money every month, which handing back somebody else's
        // URL did not. An asset nobody has asked for in this many days is
        // collected by media:prune.
        'retention_days' => (int) env('MEDIA_RETENTION_DAYS', 30),
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

        // The control video, used only to prove the extractor still works.
        // Never returned to a client.
        //
        // "Me at the zoo": the first video ever uploaded to YouTube, 19s long,
        // and about as unlikely to be deleted as anything on the platform.
        // Stability is the entire requirement here. The previous choice,
        // yt-dlp's own BaW_jenozKc test video, has since been removed, which
        // made the self-test report a broken extractor permanently. A health
        // check that cries wolf is worse than none, because it trains everyone
        // to ignore it.
        'selftest_url' => env('YT_DLP_SELFTEST_URL', 'https://www.youtube.com/watch?v=jNQXAC9IVRw'),
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
        // oEmbed costs zero API quota and is the only free source of aspect
        // ratio. Verified against live YouTube on 2026-08-16:
        //
        //   9FDVvWR91ww  /watch?v=  -> 200x113 (1.77)
        //   9FDVvWR91ww  /shorts/   -> 113x200 (0.565)
        //   OzK3PNF0WqU  /watch?v=  -> 200x113 (1.77)
        //   OzK3PNF0WqU  /shorts/   -> 200x113 (1.77)
        //
        // Two conclusions, both load-bearing:
        //
        // 1. The /shorts/ form is required. The /watch form reports 16:9 even
        //    for genuinely vertical video, so querying it alone would render
        //    every portrait clip into a landscape box.
        // 2. The /shorts/ form does NOT force portrait. It returned 16:9 for
        //    OzK3PNF0WqU, which is a landscape video that merely surfaced in a
        //    shorts search. oEmbed is telling the truth in both cases, so its
        //    answer must be trusted as returned. A "short duration therefore
        //    9:16" heuristic may only run when oEmbed fails outright, never as
        //    an override, or it will corrupt landscape videos in a short feed.
        //
        // Note the returned size is the embed player box, not the encode, so
        // only the ratio is meaningful; it is snapped to a nominal encode.
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
