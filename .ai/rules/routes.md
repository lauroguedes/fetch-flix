---
paths:
  - routes/api.php
---

# Routes

## The unauthenticated endpoints are deliberate
/health carries no auth so it stays reachable when token issuance or the
database is the thing that is broken. It exposes no tenant data.
/stream carries no auth because the client hands the URL to a plain downloader
that sends no headers of its own — the signature IS the credential, which is
why the URLs are short-lived and the asset key is a hash rather than a video
id. `ValidateStreamSignature` exists instead of Laravel's `signed` middleware
to tell expired (410, re-resolve) apart from tampered (403, stop).

## Every public endpoint needs a rate limit
/stream and /health carry no token, so they are throttled by IP rather than
by tenant. This is not optional: a /stream request for an asset still
downloading holds a PHP worker for the whole `wait_ms` window while polling
the row, so without a ceiling a few dozen concurrent callers exhaust the FPM
pool and take the service down — /health included.
Keep the stream limit generous (120/min). A local-disk response answers each
byte-range request separately, so a player seeking through a video is a
legitimate burst, not abuse.
