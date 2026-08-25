---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## tenant:token has two entry points, both required
Bare, it runs a Laravel Prompts flow that registers a tenant and scopes its token. With a slug argument it keeps the original non-interactive contract — a deploy script piping this command must never block on a prompt, so do not fold the two paths together.

It also guards on `$this->input->isInteractive()`: without a terminal every prompt falls through to its default and the first required one throws, spraying a stack trace over a CI log.

Testing: under the test harness Prompts fall back to Symfony questions, and a failed `validate:` closure **aborts instead of re-asking**. A retry cannot be expressed with `expectsQuestion`. Assert the rule directly instead (see `Tenant::slugIsAvailable()`), or the test documents the fallback rather than what an operator sees.
