---
paths:
  - 'app/Api/**'
---

# Api

## Only 429 and 5xx are retryable
Clients retry 429 and 5xx and nothing else. A permanent condition must never
map to a retryable status, and a transient one must never map to a 4xx.
`ErrorCode::isRetryable()` is derived from `httpStatus()` so the two cannot
drift apart — keep it that way.
`expired` is 410, not 503: the contract is to re-resolve with a fresh request
id, and a 5xx would retry the same id straight into the same expired entry.
These enum values are public API contract; renaming one requires a new version.

## ApiException is the only client-facing exception
Everything on /api/v1 renders through `ApiException::toResponse()` via
`ExceptionMapper`, so the error envelope has exactly one implementation.
Anything else that escapes becomes a retryable 503, never a raw 500.
Passing `retryAfter` to a non-retryable code throws `LogicException` on
purpose: a retry hint on a permanent condition makes clients retry forever.
Internal diagnostics go in `$context` (logged, never serialized to a client).
