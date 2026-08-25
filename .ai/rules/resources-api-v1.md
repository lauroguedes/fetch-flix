---
paths:
  - app/Http/Resources/Api/V1/DiscoverResource.php
  - 'app/Http/Resources/Api/V1/**'
---

# Resources Api V1

## Discovery returns a resolvable url, not just an id
Each card carries `provider` and a `url` built server-side via `Provider::watchUrl()`, and `url` is what a client feeds back to POST /v1/resolve. Returning a bare id would force every consumer to know how to rebuild a YouTube URL — the exact coupling this API exists to remove, and the thing that breaks when a second provider is added.

`VideoCard::provider` is defaulted rather than required so cached pages written before the field existed still rehydrate. Do not make it required without draining `cached_discoveries` and the Redis discovery keys first.

## Scramble only documents what it can read statically
A `toArray()` that builds its payload with `array_map` and a closure documents as a bare `array`: the generator cannot see inside the closure, so every field vanishes from the docs. Return a literal array, or `SomeResource::collection(...)`, and nothing else.

A resource wrapping a *list* must extend `ResourceCollection` with a `$collects` property, not `JsonResource`. A JsonResource cannot declare what it wraps and trips the JR001 diagnostic; an `@property array<int, Model>` annotation does not satisfy it.

Comments above each key become the public field descriptions. A comment above a pair of keys attaches only to the first, so give each its own line.

`php artisan scramble:analyze` must exit clean. It is the only thing that catches this — the docs still render, just uselessly.
