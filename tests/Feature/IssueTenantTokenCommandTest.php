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
