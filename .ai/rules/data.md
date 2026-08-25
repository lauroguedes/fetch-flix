---
paths:
  - 'app/Media/Data/**'
---

# Data

## Version the discovery cache key when the card shape changes
`DiscoveryQuery::cacheKey()` starts with a version segment (`discover:v2:...`). It keys both cache layers — the `Cache::flexible` entry and the `cached_discoveries` row — so bumping it retires both at once.

Bump it whenever `VideoCard::toArray()` gains or renames a field. That is what lets `VideoCard::fromArray()` dereference new keys directly instead of accumulating `?? 'default'` shims for payloads written by older builds.
