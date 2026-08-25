---
paths:
  - 'app/Http/Middleware/**'
---

# Middleware

## Client telemetry comes from the X-Client header only
`X-Client: <platform>/<app version>` is the only source. There is no body-block
fallback: a `client` object in the payload is ignored, not read and not
rejected. Do not carry an API version here — the URI path already states it.
Read straight from the request, never from validated input: a call that failed
validation is still attributed to the app that sent it, which is exactly when
the version matters most. That makes the value untrusted — always truncate to
the column width instead of writing it as received.
