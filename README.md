# Fetch Flix

[![Tests](https://github.com/lauroguedes/fetch-flix/actions/workflows/tests.yml/badge.svg)](https://github.com/lauroguedes/fetch-flix/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/php-8.5-777bb4.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-13.x-ff2d20.svg)](https://laravel.com/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

An API that turns a video URL into one downloadable file, finds videos worth downloading, and serves the bytes itself.

Three things, and nothing else: **resolve**, **discover**, **stream**.

> [!NOTE]
> **YouTube is the only provider supported today.** TikTok and Instagram are the planned next ones. The provider layer is built for it, so adding them changes nothing in the API contract.

---

## Table of Contents

- [Why I built this](#why-i-built-this)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [API](#api)
  - [Authentication](#authentication)
  - [POST /api/v1/resolve](#post-apiv1resolve)
  - [POST /api/v1/discover](#post-apiv1discover)
  - [GET /api/v1/categories](#get-apiv1categories)
  - [GET /api/v1/stream/{asset}](#get-apiv1streamasset)
  - [GET /api/v1/health](#get-apiv1health)
  - [Errors](#errors)
- [Interactive documentation](#interactive-documentation)
- [Architecture](#architecture)
- [Operations](#operations)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

---

## Why I built this

I kept needing videos from YouTube, TikTok and Instagram as raw material for side projects: a clip to cut into a demo, a reference to study, footage to feed into something else. Every time it meant hunting for a sketchy download site, or gluing together a one-off script that broke the following week.

So I built the thing I wanted: a small API I can call from any project. Give it a URL, get back a file I can actually download. YouTube came first because it is the source I reach for most and the hardest one to get right; TikTok and Instagram are next.

The other half of the reason is that I wanted to see the flow up close. Extracting a media URL, dealing with signed CDN links that expire or refuse the wrong IP, caching, keeping a synchronous request inside a sane time budget, storing the bytes and expiring them again. None of that is complicated in isolation, and all of it is more interesting than it looks once the pieces have to work together. This project is where I learned it by building it.

## Requirements

| | |
|---|---|
| PHP | 8.5 (8.3+ supported) |
| Laravel | 13.x |
| Database | SQLite, MySQL, or PostgreSQL |
| Redis | Cache, queues, and the single-flight lock |
| [yt-dlp](https://github.com/yt-dlp/yt-dlp) | The extractor |
| A JavaScript runtime | [Deno](https://deno.com/) or Node, **on the extractor's `PATH`** |
| YouTube Data API key | Only for `/discover` |

> [!IMPORTANT]
> The JavaScript runtime is not optional. Solving YouTube's `n` challenge requires one, and without it yt-dlp still **exits zero** while silently dropping every format that needs a PO token. The symptom is `media_missing` for videos that resolve perfectly from your terminal, because your shell's `PATH` has the runtime and PHP-FPM's does not. Set `YT_DLP_PATH` if the runtime lives outside the binary's own directory.

## Installation

```bash
git clone git@github.com:lauroguedes/fetch-flix.git
cd fetch-flix

composer setup   # install, .env, key:generate, migrate, npm install, npm run build
```

Then start the app and its workers:

```bash
php artisan dev
```

`php artisan dev` runs the server, logs, Vite, and a queue listener in one place. The listener is a replacement, not the framework default: it is bound to the queues this service actually uses (`resolve`, `downloads`). The default listens only on `default`, which would silently ignore every job dispatched here, so resolves would succeed while downloads never became available. Run `php artisan dev:list` to see the processes it starts.

Create a tenant and issue a token:

```bash
php artisan db:seed --class=LocalDevSeeder
php artisan tenant:token local-dev
```

## Configuration

Everything media-related lives in [`config/media.php`](config/media.php), which documents each value and the reasoning behind it. The settings you are most likely to touch:

| Variable | Default | What it does |
|---|---|---|
| `YOUTUBE_API_KEY` | — | Required for `/discover`. Absent, that endpoint returns `503`. |
| `YOUTUBE_DAILY_QUOTA` | `10000` | Shared daily budget. One search costs **100 units**. |
| `YOUTUBE_QUOTA_RESERVED` | `4000` | Held back for scheduled warming so live search cannot starve it. |
| `YT_DLP_BINARY` | `yt-dlp` | Path to the binary. |
| `YT_DLP_PATH` | — | Extra `PATH` entries for the JavaScript runtime. |
| `YT_DLP_PLAYER_CLIENTS` | `mweb,tv_simply` | **Pinned.** See below. |
| `MEDIA_DISK_DRIVER` | `local` | `local`, or `s3` for S3/Cloudflare R2. |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | — | Bucket credentials, read only when the driver is `s3`. |
| `AWS_BUCKET` | — | Bucket name. |
| `AWS_DEFAULT_REGION` | `auto` | `auto` for R2; the real region for S3. |
| `AWS_ENDPOINT` | — | R2 account endpoint. Leave empty for AWS S3. |
| `MEDIA_RETENTION_DAYS` | `30` | Assets unrequested this long are collected. |
| `MEDIA_SIGNED_TTL` | `21600` | Lifetime of a signed delivery URL. |
| `MEDIA_MAX_BYTES` | `524288000` | Deployment-wide ceiling, on top of per-tenant limits. |
| `SANCTUM_TOKEN_EXPIRATION` | `525600` | Minutes (365 days) before any issued token expires. |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | Which hops may set `X-Forwarded-*`. See below. |

> [!IMPORTANT]
> `TRUSTED_PROXIES` matters more than it looks. `/stream` and `/health` are unauthenticated, so their rate limits are keyed by client address, and a trusted `X-Forwarded-For` is a limit anyone can rotate past, on an endpoint that can hold a worker for twenty seconds per request. The default covers nginx on the same host, which is the Forge layout. Put a CDN's ranges here if you add one, and only use `*` if the application is genuinely unreachable except through a proxy.

> [!WARNING]
> `YT_DLP_PLAYER_CLIENTS` is pinned because the unpinned default does not work: it extracts successfully and then `403`s on every download, a failure a resolve-only health check would call healthy. Two clients are listed so one breaking degrades to the other instead of to an outage. Re-validate with `php artisan test --group=network` before changing it.

### Per-tenant limits

A tenant is a consuming application, not a person. Each carries its own ceilings, applied on top of the deployment-wide ones: `rate_limit_rpm`, `discover_rpm`, `quota_units_daily`, `max_bytes`, `max_duration_ms`, and `min_progressive_height`. Where a request and a tenant both set a limit, the tighter one wins.

## API

Base path: `/api/v1`. All request and response bodies are JSON.

### Authentication

Bearer tokens, issued by an operator. There is no self-serve signup. That is deliberate: restricting issuance to someone with shell access is the cheapest reduction in abuse and legal exposure there is.

A token belongs to a **tenant**, a consuming application, not a person. Run the command with no arguments and it walks you through registering one and scoping its token:

```bash
php artisan tenant:token
```

```
 Issue an API token

 Which application is this token for?
 › + Register a new application
   Acme iOS (acme-ios)

 What can this token do?
 › ◼ resolve — turn a video URL into a downloadable file
   ◼ discover — browse and search feeds

 Label for this token › ios-app
 When should it expire? › At the configured ceiling — Mon, Aug 23, 2027
```

It ends on a summary you confirm before anything is written, and prints the token once.

Pass a slug and it runs unattended instead, so provisioning scripts and CI are unaffected:

```bash
php artisan tenant:token acme --name="partner-trial" --ability=discover --expires-in-days=30
```

Without a terminal and without a slug it fails with one line rather than a stack trace.

For a throwaway development tenant, `php artisan db:seed --class=LocalDevSeeder` seeds one with the slug `local-dev`.

#### Withdrawing access

Two different levers, and picking the wrong one costs an integrator an afternoon.

**`tenant:suspend`** is the reversible one. `EnsureTenantActive` refuses every request at the edge while the tokens stay intact, so an integration paused over a billing dispute or a runaway client resumes with `tenant:activate` and no reissue.

```bash
php artisan tenant:suspend acme      # 403 on the next request
php artisan tenant:activate acme     # the same tokens work again, unchanged
```

**`tenant:revoke`** is the permanent one, and the only answer to a leaked credential. Deleting the row ends access on the very next request, since Sanctum looks the token up each time.

```bash
php artisan tenant:revoke                            # guided: pick from a table of tokens
php artisan tenant:revoke acme --id=42               # one token
php artisan tenant:revoke acme --all                 # every token this tenant holds
```

Both confirm before acting when run in a terminal; pass `--no-interaction` in a script. The guided flow for `tenant:revoke` lists each token with **when it was last used**, which is what tells a dormant token from the one currently in production.

> [!NOTE]
> `sanctum:prune-expired` is **not** revocation. It deletes tokens that have already expired, precisely the set that is no longer dangerous. It is housekeeping, and it runs daily on its own.

The command prints the token once. It is stored as a hash and cannot be recovered afterwards. Send it as `Authorization: Bearer <token>`, or paste it into the **Authorize** box at [`/docs/api`](#interactive-documentation) to try the endpoints from the browser.

An expired, revoked, or under-scoped token answers `403`, never `401`.

Every token expires. `SANCTUM_TOKEN_EXPIRATION` is a ceiling on all of them (365 days by default) and `--expires-in-days` sets a tighter per-token expiry; the stricter of the two wins. There is no self-serve refresh, so reissue with the same command and revoke the old token.

Token abilities act as endpoint scopes, so a discovery-only consumer cannot spend resolver capacity:

```http
Authorization: Bearer <token>
X-Request-Id: 6f1c2b9e-1f2a-4c8f-9a3e-1d0e5b7c2a11
X-Client: ios/1.4.0
```

`X-Request-Id` is optional and echoed back. **Reuse the same value across your own retries of one logical action.** A repeated id replays the stored response instead of paying for a second extraction.

`X-Client` is `<platform>/<app version>`, telemetry only. It never changes behaviour, but it is what answers "is this every consumer, or one bad build?" during an incident.

### POST /api/v1/resolve

Turns a URL into one downloadable file.

**`url` is the only required field.** Everything else has a default:

```bash
curl -X POST https://your-host/api/v1/resolve \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Client: ios/1.4.0" \
  -H "X-Request-Id: $(uuidgen)" \
  -d '{ "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ", "max_height": 720 }'
```

| Field | Required | Notes |
|---|---|---|
| `url` | **yes** | Watch, `youtu.be`, Shorts, embed, or live URL. |
| `max_height` | no | Ceiling, not an exact request. `360`, `480`, `720`, or `1080`; defaults to `720`. See the note below: today every value resolves to 360p. |
| `max_duration_ms` | no | Milliseconds (`60000` = 1 min). Refuses longer media with a `422` instead of downloading it. Unset means no limit from you; the tenant's own ceiling still applies. |
| `audio_only` | no | Returns an audio-only stream. Defaults to `false`. |
| `prefer` | no | Defaults to `progressive`, which is also the only value implemented, since the client cannot merge separate DASH audio and video. Sending anything else is a `422` rather than a silent fallback, so a future `adaptive` mode stays an additive change. |

```json
{
  "status": "ok",
  "provider": "youtube",
  "id": "dQw4w9WgXcQ",
  "title": "…",
  "author": "…",
  "duration_ms": 213000,
  "thumbnail_url": "https://…",
  "media": {
    "url": "https://your-host/api/v1/stream/8f14e45f…?signature=…",
    "mime_type": "video/mp4",
    "container": "mp4",
    "bytes": 12582912,
    "width": 640,
    "height": 360,
    "has_audio": true,
    "has_video": true,
    "headers": {},
    "expires_at": "2026-08-22T18:00:00Z",
    "degraded": true,
    "requested_height": 720
  },
  "request_id": "6f1c2b9e-…"
}
```

> [!NOTE]
> **`degraded: true` is normal, not an error, and with YouTube it is currently the rule.** Every rendition above 360p is published as HLS, which this API deliberately does not serve, so itag 18 at 360p is the only progressive format left. That makes `max_height` effectively inert today: `360` reports `degraded: false`, and `480`, `720` and `1080` all return the same 360p file with `degraded: true` and `requested_height` echoed back. The values stay accepted so the ceiling still means something if a taller progressive format returns or another provider is added. Read `media.width` and `media.height` for what you actually got.

`media.url` points back at this service and needs no headers. The first caller for a given video may wait briefly at `/stream` while the download completes; every later caller, across every tenant, is served from disk.

### POST /api/v1/discover

Finds videos, by search term or by curated category.

```bash
curl -X POST https://your-host/api/v1/discover \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "kind": "search", "query": "lofi guitar", "max_duration_ms": 180000 }'
```

| Field | Required | Notes |
|---|---|---|
| `kind` | yes | `search` or `category`. No default feed. |
| `query` | if `search` | 2–100 characters. Whitespace normalised before caching; word order preserved because it changes ranking. |
| `category` | if `category` | A slug from `GET /api/v1/categories`. |
| `max_duration_ms` | no | Milliseconds. **Defaults to `180000` (3 minutes)**; omitting it does not mean "no limit". Send `null` to lift it. |
| `page_token` | no | The `next_page_token` from a previous response, unchanged. |

```json
{
  "videos": [
    {
      "id": "9FDVvWR91ww",
      "provider": "youtube",
      "url": "https://www.youtube.com/watch?v=9FDVvWR91ww",
      "title": "…",
      "channel": "…",
      "duration_ms": 45000,
      "thumbnail_url": "https://…",
      "width": 1080,
      "height": 1920
    }
  ],
  "next_page_token": "CDIQAA"
}
```

Pass `url` straight to `POST /api/v1/resolve`. It is there so a consumer never has to know how to turn a provider id back into a URL, and that coupling would break the day a second provider is added.

`width` and `height` are **never null**. Consumers size a player frame before any media loads, so a missing dimension would render every portrait clip into a landscape box. They come from a fallback chain that always terminates in a configured default.

> [!TIP]
> A search costs **100 quota units** against a budget shared by every consumer of the service, roughly 100 searches per day for the whole project. Results are cached for 6 hours and served stale for up to 24 while refreshing in the background, so a cache hit is free. Prefer categories where you can: they are warmed off-peak from a reserved budget.

### GET /api/v1/categories

The curated feeds this deployment serves. Each `slug` is a legal `category` on `/discover`; `label` is display text.

```bash
curl https://your-host/api/v1/categories -H "Authorization: Bearer $TOKEN"
```

```json
{
  "categories": [
    { "slug": "trending", "label": "Trending" },
    { "slug": "comedy", "label": "Comedy" }
  ]
}
```

Costs no discovery quota and changes rarely, so fetch it once at startup rather than before every discover call. Requires the `discover` ability, since the list is useful for nothing else. Retired categories are never listed, because advertising a slug that `/discover` would reject is worse than listing nothing.

### GET /api/v1/stream/{asset}

Serves the file. **Unauthenticated by design**: the signature in the URL *is* the credential, because the client hands this URL to a plain downloader that sends no headers of its own.

**Do not build this URL.** Take `media.url` from a `/resolve` response and use it exactly as given: `expires` and `signature` are computed by this service, and the signature is the only credential. For the same reason it cannot be exercised from the interactive docs page: "Try it" sends no valid signature and answers `403`.

A local disk is streamed with byte-range support; a remote bucket gets a `302` to a short-lived pre-signed URL so the media never passes through the application. A `410` means the link expired and you should resolve again; a `403` means the signature does not match.

### GET /api/v1/health

Answers at two depths on one URL, so monitoring keeps a single endpoint.

**Anonymous** — enough for a load balancer to route and an uptime monitor to page:

```json
{ "status": "ok", "extractor": { "state": "ok" }, "storage": { "reachable": true }, "request_id": "…" }
```

**With a valid tenant token** — adds `extractor.yt_dlp_version`, `checked_at`, `failure_code`, hourly breakage and bot-check counts, `discovery.quota_remaining`, and storage `driver`/`stored`/`pending`/`failed`/`bytes`.

> [!NOTE]
> The detail is withheld from anonymous callers deliberately. It fingerprints the deployment, and `quota_remaining` would let an outsider time an exhaustion attack against a budget shared by every consumer, then confirm it worked. Keeping the aggregate behind the token also keeps the public probe cheap to serve.

### Errors

Every failure returns the same envelope:

```json
{
  "status": "error",
  "code": "resolver_unavailable",
  "message": "The resolver is temporarily unavailable.",
  "retry_after": 2,
  "request_id": "6f1c2b9e-…"
}
```

**Retry `429` and `5xx`. Nothing else.** A permanent condition never maps to a retryable status, and a transient one never maps to a `4xx`.

| Code | Status | Retry? |
|---|---|---|
| `unresolvable` | 422 | No |
| `too_long` | 422 | No |
| `media_missing` | 422 | No |
| `invalid_request` | 422 | No |
| `private_or_restricted` | 403 | No |
| `unauthorized` | 403 | No |
| `region_blocked` | 451 | No |
| `too_large` | 413 | No |
| `expired` | 410 | No, **re-resolve instead** |
| `rate_limited` | 429 | Yes, after `retry_after` |
| `resolver_unavailable` | 503 | Yes |
| `offline` | 502 | Yes |
| `download_failed` | 502 | Yes |

Two mappings are deliberate. `expired` is `410` rather than a `5xx` so a client re-resolves with a fresh request id instead of retrying the same id straight back into the same expired entry. And `rate_limited` is `429` even when the cause is an exhausted daily API quota, because "out of quota today" is a wait rather than a fault. The `X-Quota-Scope` header tells you whether you spent your own allowance or the whole project's.

## Interactive documentation

[Scramble](https://scramble.dedoc.co/) generates an OpenAPI 3.1 spec from the code itself (the FormRequests, Resources, and types), so it cannot drift from the implementation.

| | |
|---|---|
| Browsable docs, with try-it-out | `/docs/api` |
| Raw OpenAPI 3.1 spec | `/docs/api.json` |
| Export to a file | `composer docs:export` |

Paste a tenant token into the page's authorise box to exercise `/resolve`, `/discover` and `/categories` against a running instance. `/stream` is the one endpoint that cannot be tried from there: its credential is a signature this service computes, so the page has nothing valid to send and will answer `403`. Use the `media.url` a `/resolve` call returns instead.

> [!NOTE]
> Access is restricted to local environments by default. To expose it elsewhere, adjust `middleware` in [`config/scramble.php`](config/scramble.php), and gate it, since it describes your entire API surface.

## Architecture

Two constraints shape everything below. Both were forced from the outside rather than chosen.

**The service stores the file itself, instead of handing back the provider's link.**
A provider signs its stream URL against the IP that asked for it. Our server does the asking, so the link only works from our server. Hand it to a phone on another network and the CDN answers `403`, and no header or retry gets around it. So `/resolve` downloads the file to our own disk and returns a signed URL of ours, which works from anywhere. The trade-off: we now hold copies of other people's media, which is why retention and pruning are part of the design rather than an afterthought.

**A resolve has to answer within 12 seconds.**
Clients call `/resolve` and block on the answer, and there is no "come back later and poll" path, so the response must already carry something usable. Every piece of the resolve path exists to fit inside that ceiling: caching so most calls skip the work entirely, a lock so ten callers don't extract the same video ten times, a hard deadline on yt-dlp so a slow extraction can't hold the request open, and a queued fallback that finishes the job anyway when the deadline is missed, so the client's retry a moment later finds it already done.

```
app/
├── Api/              Error taxonomy, request ids, usage accounting
├── Http/             Controllers, middleware, form requests, resources
├── Media/
│   ├── Data/         Immutable DTOs (MediaRequest, ResolvedMedia, VideoCard…)
│   ├── Discovery/    Feed caching, quota guard, dimension resolution
│   ├── Jobs/         Queued download and the resolve fallback
│   ├── Providers/    YouTube: URL parsing, Data API, oEmbed
│   ├── Storage/      Asset store, repository, delivery, downloader
│   └── YtDlp/        Process wrapper, format selection, error classification
└── Models/
```

**A resolve, end to end:**

1. **Request-id replay** — a repeated `X-Request-Id` returns the stored envelope.
2. **Shared cache** — same video, same height, same extraction, regardless of tenant.
3. **Stored asset** — a file already on disk turns an 8-second process spawn into one indexed read. Survives a cache flush.
4. **Single-flight lock** — concurrent callers for the same video wait; the loser is told to retry rather than held past the deadline.
5. **Extraction** — yt-dlp under a hard deadline, choosing one progressive `https` format. HLS entries advertise `ext: mp4` and are rejected: their URLs are playlists, not files.
6. **Registration** — the asset row is created and the transfer queued. The signed URL is returned *before* the file exists; `/stream` absorbs the gap.

Miss the deadline and the extraction is re-run on a queue, so the client's retry moments later hits a warm cache. Two writers, one envelope shape.

**Providers.** YouTube is the only one wired up today, registered under `providers` in [`config/media.php`](config/media.php) and covering URL parsing, the Data API, and oEmbed. TikTok and Instagram are on the roadmap.

Adding one means a single class implementing `App\Media\Provider` and a single entry in that config. Nothing in the controllers, the resolve path, or the response shape changes, which is also why `/discover` returns a ready-to-use `url` instead of a bare provider id: callers never learn how to rebuild one, so a second provider stays invisible to them.

## Operations

| Command | Purpose |
|---|---|
| `tenant:token [slug]` | Register a tenant and issue a token. Guided when bare. |
| `tenant:revoke [slug]` | **Revoke a token.** Permanent. `--id`, `--all`. |
| `tenant:suspend [slug]` | Refuse a tenant's requests, keeping its tokens. Reversible. |
| `tenant:activate [slug]` | Undo a suspension. |
| `yt-dlp:selftest` | Resolve **and transfer** a control video, recording health. |
| `yt-dlp:update` | Update the binary, verify, roll back on failure. |
| `discover:warm` | Pre-fetch category feeds from the reserved budget. |
| `media:prune` | Collect assets past retention. `--days`, `--dry-run`. |

Scheduled: self-test hourly, update nightly at 03:20, warming every six hours, pruning at 04:10, retention sweep daily.

> [!IMPORTANT]
> The self-test **transfers** the file, it does not merely resolve it. The two fail independently, and the interesting failure is the one where they disagree: a well-formed URL that `403`s on download looks perfectly healthy to a resolve-only check.

## Testing

```bash
composer test          # Pint + PHPStan + the suite
php artisan test --compact
php artisan test --group=network   # hits live YouTube; excluded by default
```

The suite runs entirely offline: the extractor and the downloader are both substituted with fakes. Static analysis is [Larastan](https://github.com/larastan/larastan) at **level 7**.

## Contributing

1. Branch from `main`.
2. Read [`.ai/rules/index.md`](.ai/rules/index.md) and follow the rule files whose globs match what you are touching. Conventions live in [`CLAUDE.md`](CLAUDE.md).
3. Every change needs a test.
4. Run `composer test`; CI runs the same command.
5. Format with `vendor/bin/pint --dirty`.

## License

Released under the [MIT License](LICENSE).

> [!CAUTION]
> This software locates and redistributes third-party media. How you use it is your responsibility: respect the terms of service of any platform you point it at, and the rights of the people who made what you are downloading.
