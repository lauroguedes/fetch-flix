---
paths:
  - 'app/Console/Commands/Tenant/**'
---

# Tenant

## Suspension and revocation are different levers
`tenant:suspend` is reversible: `EnsureTenantActive` refuses at the edge while the tokens stay intact, so `tenant:activate` restores service with no reissue. `tenant:revoke` deletes the credential and is permanent. Never treat one as the other — an integrator pays for the mistake.

`sanctum:prune-expired` is not revocation. It removes tokens that have already expired, which is exactly the set that is no longer dangerous.

Every destructive path confirms when `$this->input->isInteractive()`, and scripts pass `--no-interaction`. The guided revoke lists `last_used_at` per token: it is what distinguishes a dormant token from the one currently in production.
