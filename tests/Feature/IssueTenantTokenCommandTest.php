<?php

use App\Models\Tenant;

it('issues a working token with the requested abilities', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:token', ['slug' => 'acme', '--ability' => ['resolve']])
        ->assertSuccessful();

    expect($tenant->tokens()->count())->toBe(1)
        ->and($tenant->tokens()->first()->abilities)->toBe(['resolve']);
});

it('grants both abilities when none are specified', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:token', ['slug' => 'acme'])->assertSuccessful();

    expect(Tenant::firstWhere('slug', 'acme')->tokens()->first()->abilities)
        ->toBe(['resolve', 'discover']);
});

it('refuses an unknown ability rather than issuing an unusable token', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:token', ['slug' => 'acme', '--ability' => ['admin']])
        ->assertFailed();

    expect(Tenant::firstWhere('slug', 'acme')->tokens()->count())->toBe(0);
});

it('fails cleanly for an unknown tenant', function () {
    $this->artisan('tenant:token', ['slug' => 'nope'])->assertFailed();
});

it('bounds every token by the configured ceiling', function () {
    // A token that never expires is a leak that never closes, and there is no
    // self-serve refresh to recover from one.
    expect(config('sanctum.expiration'))->toBeInt()->toBeGreaterThan(0);
});

it('accepts a tighter per-token expiry', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:token', ['slug' => 'acme', '--expires-in-days' => 7])
        ->assertSuccessful();

    $expiresAt = Tenant::query()->where('slug', 'acme')->firstOrFail()
        ->tokens()->firstOrFail()->expires_at;

    expect($expiresAt)->not->toBeNull()
        ->and($expiresAt->isBetween(now()->addDays(6), now()->addDays(8)))->toBeTrue();
});

it('refuses a nonsensical expiry rather than issuing an immortal token', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:token', ['slug' => 'acme', '--expires-in-days' => 0])
        ->assertFailed();

    expect(Tenant::query()->where('slug', 'acme')->firstOrFail()->tokens()->count())->toBe(0);
});

it('registers a tenant and issues its token in one guided run', function () {
    // The whole point of the guided flow: an operator with no tenant yet ends
    // the command holding a usable token.
    expect(Tenant::query()->count())->toBe(0);

    $this->artisan('tenant:token')
        ->expectsQuestion('Name of the consuming application', 'Acme iOS')
        ->expectsQuestion('Slug', 'acme-ios')
        ->expectsConfirmation('Set per-tenant limits now?', 'no')
        ->expectsQuestion('What can this token do?', ['resolve'])
        ->expectsQuestion('Label for this token', 'ios-app')
        ->expectsQuestion('When should it expire?', '30')
        ->expectsConfirmation('Issue this token?', 'yes')
        ->assertSuccessful();

    $tenant = Tenant::firstWhere('slug', 'acme-ios');

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Acme iOS')
        ->and($tenant->isActive())->toBeTrue()
        ->and($tenant->tokens()->count())->toBe(1);

    $token = $tenant->tokens()->first();

    expect($token->name)->toBe('ios-app')
        ->and($token->abilities)->toBe(['resolve'])
        ->and($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->diffInDays(now()))->toBeLessThan(31);
});

it('issues nothing when the summary is declined', function () {
    Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);

    $this->artisan('tenant:token')
        ->expectsQuestion('Which application is this token for?', 'acme')
        ->expectsQuestion('What can this token do?', ['resolve', 'discover'])
        ->expectsQuestion('Label for this token', 'default')
        ->expectsQuestion('When should it expire?', 'ceiling')
        ->expectsConfirmation('Issue this token?', 'no')
        ->assertSuccessful();

    expect(Tenant::firstWhere('slug', 'acme')->tokens()->count())->toBe(0);
});

it('refuses a slug that is already taken', function () {
    // Two tenants sharing a slug would make `tenant:token acme` ambiguous, and
    // the operator would not know which one they had just credentialled.
    //
    // The rule is asserted directly rather than through the prompt: under the
    // test harness a failed `validate:` closure aborts instead of re-asking,
    // so driving it through the command would document the fallback rather
    // than what an operator actually sees.
    Tenant::factory()->create(['slug' => 'acme']);

    expect(Tenant::slugIsAvailable('acme'))->toBeFalse()
        ->and(Tenant::slugIsAvailable('acme-again'))->toBeTrue();
});

it('warns before crediting a suspended tenant', function () {
    // A token issued here is refused at the edge by EnsureTenantActive, so
    // handing one over without saying so wastes an integrator's afternoon.
    Tenant::factory()->suspended()->create(['slug' => 'acme', 'name' => 'Acme']);

    $this->artisan('tenant:token')
        ->expectsQuestion('Which application is this token for?', 'acme')
        ->expectsConfirmation('Issue anyway?', 'no')
        ->assertFailed();

    expect(Tenant::firstWhere('slug', 'acme')->tokens()->count())->toBe(0);
});

it('records the per-tenant limits it collects', function () {
    $this->artisan('tenant:token')
        ->expectsQuestion('Name of the consuming application', 'Acme')
        ->expectsQuestion('Slug', 'acme')
        ->expectsConfirmation('Set per-tenant limits now?', 'yes')
        ->expectsQuestion('Resolve requests per minute', '120')
        ->expectsQuestion('Discovery requests per minute', '10')
        ->expectsQuestion('Daily discovery quota units', '500')
        ->expectsQuestion('Largest file to deliver, in bytes (0 for no tenant limit)', '0')
        ->expectsQuestion('Longest media to accept, in milliseconds (0 for no tenant limit)', '60000')
        ->expectsQuestion('What can this token do?', ['resolve'])
        ->expectsQuestion('Label for this token', 'default')
        ->expectsQuestion('When should it expire?', 'ceiling')
        ->expectsConfirmation('Issue this token?', 'yes')
        ->assertSuccessful();

    $tenant = Tenant::firstWhere('slug', 'acme');

    expect($tenant->rate_limit_rpm)->toBe(120)
        ->and($tenant->discover_rpm)->toBe(10)
        ->and($tenant->quota_units_daily)->toBe(500)
        // Zero means "no tenant ceiling", stored as null rather than a limit
        // of nothing.
        ->and($tenant->max_bytes)->toBeNull()
        ->and($tenant->max_duration_ms)->toBe(60000);
});

it('refuses the guided flow without a terminal instead of half-registering', function () {
    // A CI job that calls this without a slug must get one clear line, not a
    // stack trace from the first required prompt falling through to its default.
    $this->artisan('tenant:token', ['--no-interaction' => true])->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});
