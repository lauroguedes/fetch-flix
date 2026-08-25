---
paths:
  - 'app/Http/Controllers/Api/V1/**'
---

# Api V1

## /health answers at two depths, and the shallow one is public
Anonymous callers get `status`, `extractor.state`, `storage.reachable` and
nothing else — enough for a load balancer to route and a monitor to page.
A valid, active tenant token on the same URL unlocks the rest.
The detail is withheld because it fingerprints the deployment (yt-dlp version)
and because `quota_remaining` lets an outsider time an exhaustion attack on a
budget shared by every consumer, then confirm it worked. The storage aggregate
is also the expensive half, so keeping it behind the token keeps the public
probe cheap to serve.
Never move a field up into the anonymous payload without asking what it tells
someone who does not have a token.

## Docblocks on __invoke are public API documentation
Scramble builds the OpenAPI operation from the `__invoke` docblock: first line becomes the summary, the rest the description. It is published at /docs/api, so write it for a consumer, not a maintainer.

The same applies to comments above entries in a FormRequest `rules()` array — Scramble lifts them verbatim into the field description. Implementation rationale belongs in .ai/rules, never there: an earlier version leaked "so a future adaptive mode stays an additive change" into the public docs.

What middleware does is invisible to Scramble. Headers, the /stream signature query params, error responses and the defaults that live in FormRequest accessors are all declared by hand in AppServiceProvider::configureApiDocs().
