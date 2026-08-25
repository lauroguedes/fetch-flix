---
paths:
  - 'app/Media/Storage/**'
---

# Storage

## Assets are shared across tenants; transitions are conditional updates
The bytes of a public video are the same for everybody, so one `media_assets`
row serves every tenant and nothing tenant-derived is stored on it. Per-tenant
ceilings are re-applied on each warm hit in `AssetStore::cached()`.
Several workers and web requests race the same row by design, so
`MediaAssetRepository` writes conditional updates rather than read-then-write:
a lost race must be a no-op, not a second download.
`touchAccess()` must never bump `updated_at` — that is the heartbeat
`claim()` uses to detect a dead worker.

## AssetKey and its storage path are deliberately different identities
`AssetKey::for()` is keyed on the **request** (provider, video id, maxHeight,
audioOnly) so a resolve can look it up *before* spawning yt-dlp and skip
extraction on a hit.
`AssetKey::storagePath()` is keyed on the **selected format**, so two keys
share one file whenever they resolve to the same format — a 720p and a 1080p
request both fall back to itag 18.
Never assume the two agree. Pruning must call `pathIsShared()` before deleting
a file, or removing one variant breaks the other.

## AssetStore prepares, AssetDelivery serves
Two similarly named classes with unrelated jobs:
`AssetStore` is the resolve path — cache lookup, register the row, queue the
transfer, build the `ResolvedMedia` envelope. It returns no bytes.
`AssetDelivery` is the stream path — returns a `Response`, redirecting to a
pre-signed URL on a remote disk or streaming with byte-range support locally.
The branch is on the disk *driver*, not `providesTemporaryUrls()`: a local
disk with `serve` enabled can mint temporary URLs too, and redirecting to one
would send the client back through this app for a local file.

## Stream URLs are signed relative to the root
`AssetStore::signedUrl()` passes `absolute: false`, and `ValidateStreamSignature` validates the same way. Keep both in step.

An absolute signature covers the scheme and host, which are not knowable everywhere this runs: a queue worker has no request and falls back to `app.url`, and the envelope it writes is then served from the cross-tenant cache to real requests. Any drift — http behind an https proxy is the usual one — made every download 403 for the life of the cache entry.

`ResolveResource` makes the path absolute against the serving host. `url()` passes already-absolute values through untouched, which is what lets older cached envelopes keep working.
