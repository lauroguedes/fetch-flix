---
paths:
  - 'app/Media/**'
---

# Media

## Resolve is synchronous, on a 12s client budget
The client calls /resolve from a blocking runloop with no polling branch, so
the response must carry usable media. Do not turn this into accept-then-poll.
The layers exist for that budget, cheapest first: request-id replay -> shared
cross-tenant cache -> already-stored asset -> single-flight lock -> extraction.
A deadline miss re-runs the extraction on a queue via
`ResolveService::extractAndCache()`, so the client's retry hits a warm cache.
Both the sync path and the job must go through that one method: caching a raw
provider result instead would put an IP-bound URL in the shared cache.

## The service hosts the bytes, and that is a posture, not plumbing
A provider stream URL is signed against the IP that extracted it: a server
resolves, a phone on another network downloads, and the CDN answers 403. No
header or retry fixes it — only serving the bytes ourselves does.
So this service redistributes media rather than merely locating it. Retention
(`media:prune`, `MEDIA_RETENTION_DAYS`) is enforced on a schedule partly
because of that, and partly because storage is the one part with a bill.
