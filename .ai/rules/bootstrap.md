---
paths:
  - bootstrap/app.php
---

# Bootstrap

## Trust the proxy, or signed downloads break in production
`$middleware->trustProxies(at: '*')` is required behind Forge's nginx or any
TLS-terminating proxy. The stream signature is computed over the full URL, so
an app that sees plain http while the link was minted as https fails every
`hasValidSignature()` check: downloads 403 in production and work perfectly in
local development.
Trusting every proxy is only safe because the app is never reachable except
through that proxy. If the PHP port is ever exposed directly, a caller can
spoof X-Forwarded-* and this must be narrowed to the load balancer's range.

## No config() or env() in the withMiddleware closure
That closure runs before the config repository is bound: `config()` throws "Target class [config] does not exist" and the application stops booting. `env()` boots fine but returns null once the config is cached, which fails silently in production instead.

Anything needing a configured value belongs in a service provider. Trusted proxies are set in `AppServiceProvider::configureProxies()` for exactly this reason.
