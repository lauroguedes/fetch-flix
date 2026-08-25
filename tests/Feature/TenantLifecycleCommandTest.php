<?php

use App\Models\Tenant;
use App\Models\TenantStatus;

function tokenFor(Tenant $tenant, string $label = 'app'): string
{
    return $tenant->createToken($label, Tenant::abilities())->plainTextToken;
}

function callsApiWith(string $token): int
{
    return withTenantToken($token)->getJson('/api/v1/categories')->status();
}

it('revokes a token and the next call is refused', function () {
    // The assertion that matters: revocation is not bookkeeping, it ends
    // access. Sanctum looks the row up on every request, so the very next call
    // fails — not the next hour.
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $token = tokenFor($tenant);

    expect(callsApiWith($token))->toBe(200);

    $this->artisan('tenant:revoke', ['slug' => 'acme', '--all' => true, '--no-interaction' => true])->assertSuccessful();

    expect(callsApiWith($token))->toBe(403)
        ->and($tenant->tokens()->count())->toBe(0);
});

it('revokes only the token it was given', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $doomed = $tenant->createToken('leaked', Tenant::abilities());
    $keep = tokenFor($tenant, 'ios');

    $this->artisan('tenant:revoke', ['slug' => 'acme', '--id' => [$doomed->accessToken->id], '--no-interaction' => true])
        ->assertSuccessful();

    expect($tenant->tokens()->count())->toBe(1)
        ->and($tenant->tokens()->first()->name)->toBe('ios')
        ->and(callsApiWith($keep))->toBe(200);
});

it('refuses to guess which token to revoke', function () {
    // Without --id or --all this would otherwise be a coin flip over someone's
    // production credential.
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    tokenFor($tenant);

    $this->artisan('tenant:revoke', ['slug' => 'acme', '--no-interaction' => true])->assertFailed();

    expect($tenant->tokens()->count())->toBe(1);
});

it('picks tokens from a list in the guided flow', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $doomed = $tenant->createToken('leaked', Tenant::abilities());
    tokenFor($tenant, 'ios');

    $this->artisan('tenant:revoke')
        ->expectsQuestion('Whose token are you revoking?', 'acme')
        ->expectsQuestion('Which tokens should stop working?', [$doomed->accessToken->id])
        ->expectsConfirmation('Revoke 1 token now?', 'yes')
        ->assertSuccessful();

    expect($tenant->tokens()->pluck('name')->all())->toBe(['ios']);
});

it('suspends a tenant without destroying its tokens', function () {
    // Suspension is the reversible lever. A tenant paused over a billing
    // dispute must resume without every consumer reinstalling a credential.
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $token = tokenFor($tenant);

    $this->artisan('tenant:suspend', ['slug' => 'acme', '--no-interaction' => true])->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->tokens()->count())->toBe(1)
        ->and(callsApiWith($token))->toBe(403);
});

it('restores access on reactivation with the same token', function () {
    $tenant = Tenant::factory()->suspended()->create(['slug' => 'acme']);
    $token = tokenFor($tenant);

    expect(callsApiWith($token))->toBe(403);

    $this->artisan('tenant:activate', ['slug' => 'acme'])->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(callsApiWith($token))->toBe(200);
});

it('treats a repeated suspend or activate as a no-op', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:activate', ['slug' => 'acme'])->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);

    $this->artisan('tenant:suspend', ['slug' => 'acme', '--no-interaction' => true])->assertSuccessful();
    $this->artisan('tenant:suspend', ['slug' => 'acme', '--no-interaction' => true])->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);
});

it('fails cleanly for an unknown tenant', function () {
    $this->artisan('tenant:revoke', ['slug' => 'nope', '--all' => true, '--no-interaction' => true])->assertFailed();
    $this->artisan('tenant:suspend', ['slug' => 'nope'])->assertFailed();
    $this->artisan('tenant:activate', ['slug' => 'nope'])->assertFailed();
});

it('refuses the guided flows without a terminal', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:revoke', ['--no-interaction' => true])->assertFailed();
    $this->artisan('tenant:suspend', ['--no-interaction' => true])->assertFailed();
    $this->artisan('tenant:activate', ['--no-interaction' => true])->assertFailed();

    expect(Tenant::firstWhere('slug', 'acme')->status)->toBe(TenantStatus::Active);
});

it('asks before revoking when someone is watching', function () {
    // Revocation is irreversible and there is no self-serve reissue, so the
    // slug-driven path still confirms in a terminal. Declining must leave the
    // credential working.
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $token = tokenFor($tenant);

    $this->artisan('tenant:revoke', ['slug' => 'acme', '--all' => true])
        ->expectsConfirmation('Revoke 1 token for [Acme]?', 'no')
        ->assertSuccessful();

    expect($tenant->tokens()->count())->toBe(1)
        ->and(callsApiWith($token))->toBe(200);
});

it('asks before suspending when someone is watching', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $this->artisan('tenant:suspend', ['slug' => 'acme'])
        ->expectsConfirmation('Suspend it?', 'no')
        ->assertSuccessful();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});
