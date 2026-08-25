---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Never cache Eloquent objects
Cache arrays, never model instances. `/v1/categories` once cached `VideoCategory` models and they came back as `__PHP_Incomplete_Class`, turning a read-only endpoint into a hard 503 that no test caught because the test cache store is `array`.

The rest of the codebase already follows this — `DiscoveryPage::toArray()`, `ResolveService` — so a cache holding objects is the anomaly, not the precedent. `/v1/categories` is now uncached outright: it is an indexed read of a handful of rows, and a cache that can hand back a broken object is worse than the query it saves.
