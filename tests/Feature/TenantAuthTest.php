<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['request-id', 'auth:sanctum', 'tenant.active'])
        ->post('/api/v1/__test/guarded', fn () => response()->json([
            'tenant' => request()->user()->slug,
        ]));

    Route::middleware(['request-id', 'auth:sanctum', 'tenant.active', 'abilities:resolve'])
        ->post('/api/v1/__test/resolve-scoped', fn () => response()->json(['ok' => true]));
});

it('rejects an unauthenticated request in the contracted envelope', function () {
    $this->postJson('/api/v1/__test/guarded')
        ->assertStatus(403)
        ->assertJson(['status' => 'error', 'code' => 'unauthorized'])
        ->assertExactJsonStructure(['status', 'code', 'message', 'retry_after', 'request_id']);
});

it('rejects an unauthenticated caller that did not ask for json', function () {
    // Laravel's Authenticate middleware resolves route('login') for requests
    // that do not look like JSON, and this application has none. The resulting
    // RouteNotFoundException surfaced as a retryable 503, so a client holding a
    // bad token would have retried a permanent failure forever.
    $this->post('/api/v1/__test/guarded', [], ['Accept' => '*/*'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('rejects a garbage bearer token', function () {
    $this->withToken('not-a-real-token')
        ->postJson('/api/v1/__test/guarded')
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('authenticates a tenant holding a valid token', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $token = $tenant->createToken('test', [Tenant::ABILITY_RESOLVE])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/__test/guarded')
        ->assertOk()
        ->assertJson(['tenant' => 'acme']);
});

it('rejects a suspended tenant as unauthorized, not as a media error', function () {
    // Suspension is an account condition. Reusing a media-semantics code such as
    // private_or_restricted would send the client down the wrong branch and make
    // the real cause undiagnosable.
    $tenant = Tenant::factory()->suspended()->create();
    $token = $tenant->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/__test/guarded')
        ->assertStatus(403)
        ->assertJson(['code' => 'unauthorized'])
        ->assertJsonPath('message', 'This tenant is suspended.');
});

it('enforces token abilities as scopes', function () {
    $tenant = Tenant::factory()->create();
    $discoverOnly = $tenant->createToken('test', [Tenant::ABILITY_DISCOVER])->plainTextToken;

    // A discovery-only consumer must not be able to spend resolver capacity.
    $this->withToken($discoverOnly)
        ->postJson('/api/v1/__test/resolve-scoped')
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('does not let a browser session authenticate against the api surface', function () {
    // config/sanctum.php sets guard => [] precisely to prevent this: a logged-in
    // User is not a Tenant and must never resolve as one.
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/__test/guarded')
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('leaves the health probe reachable without a token', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('resolves as an authenticatable so Auth::id() cannot fatal mid-request', function () {
    // Anything reaching for Auth::id() during a request used to fatal, because
    // the guard is typed against Authenticatable and Tenant was not one. Pail
    // does exactly that on every log line, so any logged error on an
    // authenticated request became a 500 with the real cause buried under it.
    $tenant = Tenant::factory()->create();
    $token = $tenant->createToken('test', [Tenant::ABILITY_RESOLVE])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/health')->assertOk();

    expect($tenant)->toBeInstanceOf(Authenticatable::class)
        ->and($tenant->getAuthIdentifier())->toBe($tenant->id)
        ->and($tenant->getAuthIdentifierName())->toBe('id')
        ->and($tenant->getRememberToken())->toBeNull();
});

it('refuses to pretend a tenant has a password', function () {
    // A tenant authenticates with a bearer token and has no password column.
    // Returning an empty string would let a password guard silently compare
    // against nothing.
    expect(fn () => Tenant::factory()->create()->getAuthPassword())
        ->toThrow(LogicException::class);
});
