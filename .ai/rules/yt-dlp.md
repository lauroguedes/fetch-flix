---
paths:
  - 'app/Media/YtDlp/**'
---

# Yt Dlp

## Player clients are pinned; re-validate with the network group
`YT_DLP_PLAYER_CLIENTS` is pinned to `mweb,tv_simply`. The unpinned default
(android_vr) *extracts successfully* and then 403s on every download, so a
resolve-only check calls it healthy. Two clients are listed so one breaking
degrades to the other instead of to an outage.
Never change this blind: run `php artisan test --group=network` first, which
performs a real transfer rather than only an extraction.

## yt-dlp needs a JavaScript runtime on PATH
Solving YouTube's `n` challenge requires a JS runtime (Deno/Node) on the
extractor's PATH. Without one yt-dlp still **exits zero** while silently
dropping every format that needs a PO token. The symptom is `media_missing`
for videos that resolve fine from a terminal, because a shell's PATH has the
runtime and PHP-FPM's does not.
`YtDlpProcess` owns the PATH for both extraction and download. Set
`YT_DLP_PATH` when the runtime does not live beside the binary.

## Only protocol=https formats are real files
YouTube's HLS ladder (itags 91-96) reports `ext: mp4` and carries both codecs,
so a codec-only filter returns a 1080p "mp4" whose URL is an .m3u8 playlist.
`FormatSelector` filters on `protocol === 'https'` — that filter is
load-bearing and must not be relaxed.
Since itag 22 was removed, itag 18 (640x360) is routinely the only true
progressive format, so `degraded: true` is the normal path, not an edge case.

## Add shared yt-dlp flags to YtDlpProcess, not to a caller
Extraction and download are tuned differently (seconds on the request vs
minutes on a queue) but run the same binary against the same site. Flags that
make it work at all — cache dir, player clients, proxy, the `--` terminator —
live in `YtDlpProcess::command()`. Callers pass only their own flags via
`flags()`. Putting a shared flag in one caller is how the two drift apart.

## Redact URLs before writing stderr anywhere
yt-dlp echoes the URLs it worked on, and a googlevideo format URL carries its
own signature and the extracting IP in the query string. Logs are long-lived,
get shipped off the box, and are read by more people than the media is meant
for, so the credential must not travel with the diagnostic.
`ExtractorReport::redact()` reduces every URL to `[url <host>]` and is applied
both where stderr is logged and where the classifier puts it in an
`ApiException` context (which `report()` also writes out). Any new path that
records stderr must go through it too. The host survives on purpose: "which
CDN answered" is most of the debugging value.
