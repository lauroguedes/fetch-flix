---
paths:
  - 'app/Media/Providers/**'
---

# Providers

## The host whitelist is the SSRF barrier
`YouTubeUrlParser::claims()` matches `parse_url`'s host against an exact
`in_array` whitelist, and `ProviderRegistry::forUrl()` refuses anything no
provider claims — before yt-dlp is ever spawned. That is what stops a caller
pointing the extractor at `file://`, at cloud metadata (169.254.169.254), or
at an internal service.
Exact matching is load-bearing: it is what rejects `youtu.be.evil.com` and
`https://youtube.com@evil.com`. Never loosen it to a suffix or substring
check, and never let a URL reach the runner without passing `claims()`.
