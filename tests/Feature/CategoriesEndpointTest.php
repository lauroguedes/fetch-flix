<?php

use App\Models\Tenant;
use App\Models\VideoCategory;
use Illuminate\Testing\TestResponse;
use Tests\Support\YouTubeApiFake;

function categoriesAs(Tenant $tenant, array $abilities = [Tenant::ABILITY_DISCOVER]): TestResponse
{
    return actingAsTenant($tenant, $abilities)->getJson('/api/v1/categories');
}

it('lists the active categories in the contracted shape', function () {
    VideoCategory::factory()->create(['slug' => 'music', 'label' => 'Music', 'sort_order' => 2]);
    VideoCategory::factory()->create(['slug' => 'comedy', 'label' => 'Comedy', 'sort_order' => 1]);

    categoriesAs(Tenant::factory()->create())
        ->assertOk()
        ->assertJsonStructure(['categories' => [['slug', 'label']]])
        // Ordered by sort_order, because the operator's ordering is the point
        // of curating them at all.
        ->assertJsonPath('categories.0.slug', 'comedy')
        ->assertJsonPath('categories.1.slug', 'music');
});

it('hides retired categories', function () {
    // A slug the discover endpoint would reject must never be advertised here,
    // or a client is handed a value that is guaranteed to 422.
    VideoCategory::factory()->create(['slug' => 'live', 'is_active' => false]);
    VideoCategory::factory()->create(['slug' => 'music', 'is_active' => true]);

    $response = categoriesAs(Tenant::factory()->create())->assertOk();

    expect(array_column($response->json('categories'), 'slug'))
        ->toContain('music')
        ->not->toContain('live');
});

it('rejects an anonymous caller', function () {
    VideoCategory::factory()->create();

    $this->getJson('/api/v1/categories')->assertStatus(403);
});

it('requires the discover ability', function () {
    VideoCategory::factory()->create();

    categoriesAs(Tenant::factory()->create(), [Tenant::ABILITY_RESOLVE])
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('serves every slug the discover endpoint accepts', function () {
    // The contract between the two endpoints: anything listed here must pass
    // discover's validation, or advertising it is worse than useless.
    config()->set('media.discovery.youtube_api_key', 'test-key');
    VideoCategory::factory()->create(['slug' => 'music', 'is_active' => true]);
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    $slug = categoriesAs(Tenant::factory()->create())->json('categories.0.slug');

    actingAsTenant(Tenant::factory()->create(), [Tenant::ABILITY_DISCOVER])
        ->postJson('/api/v1/discover', ['kind' => 'category', 'category' => $slug])
        ->assertOk();
});

it('never hands back a stale or half-rehydrated list', function () {
    // This endpoint is deliberately uncached. A cache here once held serialised
    // Eloquent models that came back as `__PHP_Incomplete_Class`, turning a
    // read-only endpoint into a hard 503. A row retired between two calls must
    // disappear on the second.
    $category = VideoCategory::factory()->create(['slug' => 'music', 'is_active' => true]);
    $tenant = Tenant::factory()->create();

    expect(array_column(categoriesAs($tenant)->json('categories'), 'slug'))->toContain('music');

    $category->update(['is_active' => false]);

    expect(array_column(categoriesAs($tenant)->json('categories'), 'slug'))->not->toContain('music');
});
