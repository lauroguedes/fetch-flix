---
paths:
  - 'app/Media/Discovery/**'
---

# Discovery

## Discovery quota is a shared, exhaustible budget
`search.list` costs 100 units of a 10,000/day allowance shared by the whole
project — roughly 100 searches per day across every consumer. Caching is the
feature, not an optimisation, and a cache miss is a budget event.
`QuotaGuard` reserves units **before** the call and never rolls back on
failure, because Google charges for invalid requests too.
Cache keys exclude `max_duration_ms` on purpose: a cached superset is filtered
in PHP so one page serves every duration variant across every tenant. Adding
it to the key would fragment the cache and multiply 100-unit calls.

## Card dimensions are never null, and oEmbed is trusted as returned
The Data API returns no width/height, but consumers size a player frame before
anything loads, so a null renders every portrait clip into a landscape box.
The chain always terminates: video_dimensions -> oEmbed -> heuristic ->
configured default.
oEmbed's answer is trusted exactly as returned. The /shorts/ form is required
(the /watch form reports 16:9 even for vertical video) but it does NOT force
portrait — it correctly reports 16:9 for a landscape video that merely
surfaced in a shorts feed. A "short duration therefore 9:16" heuristic may only
run when oEmbed fails outright, never as an override.
