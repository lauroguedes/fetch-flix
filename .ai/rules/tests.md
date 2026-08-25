---
paths:
  - 'tests/**'
---

# Tests

## The suite runs offline; network tests are opt-in
`YtDlpRunner` and `MediaDownloader` are substituted with fakes
(`tests/Support/FakeYtDlpRunner`, `FakeMediaDownloader`) bound in `TestCase`.
Never let a test reach the network by default.
`php artisan test --group=network` exercises live YouTube and is the gate for
changing player clients, format selection, or anything about extraction.

## Stray HTTP is blocked; the network group opts back in
`TestCase::setUp()` calls `Http::preventStrayRequests()`. A discovery test that forgets `YouTubeApiFake::fake()` would otherwise spend 100 units of a real shared daily quota.

The failure is quiet without the guard: `DataApiClient` catches `Throwable`, so a live call that errors surfaces as a 503 and reads like a misconfiguration rather than a bill. A test that returns real YouTube titles is the only symptom.

tests/Feature/Network/LiveExtractionTest.php calls `Http::allowStrayRequests()` in a beforeEach because it genuinely wants the network. That is the only place that should.
