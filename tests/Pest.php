<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests get the container but no database. Media services read tuning
// values (timeouts, expiry margins, header strip lists) from config/media.php,
// so they need a booted application even when they touch nothing persistent.
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Authenticate the next request as a tenant.
 *
 * Laravel's auth guards cache the resolved user, and a test keeps one container
 * alive across many requests, so without forgetting them the second call in a
 * test is still attributed to the first caller. Real requests get a fresh
 * container (and Octane flushes guards), so this restores production behaviour
 * rather than papering over a bug.
 *
 * @param  array<int, string>  $abilities
 */
function actingAsTenant(Tenant $tenant, array $abilities = [], string $label = 'test'): TestCase
{
    return withTenantToken(
        $tenant->createToken($label, $abilities === [] ? Tenant::abilities() : $abilities)->plainTextToken
    );
}

/**
 * The same, for a test that already holds the plaintext token — one revoking it
 * and calling again, for instance.
 */
function withTenantToken(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}
