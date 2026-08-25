<?php

use App\Models\CachedDiscovery;
use App\Models\Tenant;
use App\Models\VideoCategory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\YouTubeApiFake;

beforeEach(function () {
    config()->set('media.discovery.youtube_api_key', 'test-key');
});

function discoverAs(Tenant $tenant, array $payload = []): TestResponse
{
    return actingAsTenant($tenant, [Tenant::ABILITY_DISCOVER])
        ->postJson('/api/v1/discover', array_replace([
            'kind' => 'search',
            'query' => 'cats jumping',
        ], $payload));
}

it('returns cards in the contracted shape', function () {
    YouTubeApiFake::fake([
        ['id' => 'aaaaaaaaaaa', 'title' => 'One', 'channel' => 'Chan', 'duration' => 'PT42S'],
        ['id' => 'bbbbbbbbbbb', 'title' => 'Two', 'channel' => 'Chan', 'duration' => 'PT1M2S'],
    ]);

    discoverAs(Tenant::factory()->create())
        ->assertOk()
        ->assertJsonStructure([
            'videos' => [['id', 'provider', 'url', 'title', 'channel', 'duration_ms', 'thumbnail_url', 'width', 'height']],
            'next_page_token',
        ])
        ->assertJsonPath('videos.0.duration_ms', 42000)
        ->assertJsonPath('videos.1.duration_ms', 62000)
        ->assertJsonPath('next_page_token', 'CAUQAA');
});

it('never returns a null dimension', function () {
    // A missing width or height renders every portrait clip into a landscape
    // box, so the fallback chain must always terminate in a real value.
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    $response = discoverAs(Tenant::factory()->create())->assertOk();

    expect($response->json('videos.0.width'))->toBeInt()->toBeGreaterThan(0)
        ->and($response->json('videos.0.height'))->toBeInt()->toBeGreaterThan(0);
});

it('decodes html entities the api returns escaped', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa', 'title' => 'Rock &amp; Roll &#39;76']]);

    discoverAs(Tenant::factory()->create())
        ->assertOk()
        ->assertJsonPath('videos.0.title', "Rock & Roll '76");
});

it('spends the 100-unit search only once for repeated identical queries', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create())->assertOk();
    discoverAs(Tenant::factory()->create())->assertOk();
    discoverAs(Tenant::factory()->create())->assertOk();

    // One search.list plus one videos.list. The cache is the feature: without
    // it, three requests would be 300 of a 10,000 daily allowance shared by
    // every consumer of this service.
    expect(YouTubeApiFake::dataApiCalls())->toBe(2);
});

it('treats differently-spaced queries as the same cache entry', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create(), ['query' => 'cats jumping'])->assertOk();
    discoverAs(Tenant::factory()->create(), ['query' => '  CATS   Jumping  '])->assertOk();

    expect(YouTubeApiFake::dataApiCalls())->toBe(2);
});

it('serves every duration variant from one cached page', function () {
    YouTubeApiFake::fake([
        ['id' => 'aaaaaaaaaaa', 'duration' => 'PT20S'],
        ['id' => 'bbbbbbbbbbb', 'duration' => 'PT2M'],
    ]);

    $wide = discoverAs(Tenant::factory()->create(), ['max_duration_ms' => 180000])->assertOk();
    $narrow = discoverAs(Tenant::factory()->create(), ['max_duration_ms' => 30000])->assertOk();

    // Filtering happens in PHP against a cached superset, so a stricter limit
    // costs nothing extra.
    expect($wide->json('videos'))->toHaveCount(2)
        ->and($narrow->json('videos'))->toHaveCount(1)
        ->and($narrow->json('videos.0.id'))->toBe('aaaaaaaaaaa');

    expect(YouTubeApiFake::dataApiCalls())->toBe(2);
});

it('survives a redis flush without spending quota again', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create())->assertOk();

    expect(CachedDiscovery::query()->count())->toBe(1);

    // Redis is gone; the durable layer must absorb it.
    cache()->clear();

    discoverAs(Tenant::factory()->create())->assertOk();

    expect(YouTubeApiFake::dataApiCalls())->toBe(2);
});

it('distinguishes tenant quota exhaustion from a broken service', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    // 101 units of allowance: enough to be refused by the search before it runs.
    $tenant = Tenant::factory()->create(['quota_units_daily' => 50]);

    $response = discoverAs($tenant, ['query' => 'unique one'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');

    // "I burned my own allowance" and "the whole project is out today" are
    // different screens for a consumer.
    expect($response->headers->get('X-Quota-Scope'))->toBe('tenant')
        ->and($response->json('retry_after'))->toBeInt()->toBeGreaterThan(0);

    Http::assertNothingSent();
});

it('reports global exhaustion with a different scope', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);
    config()->set('media.quota.daily_limit', 10);

    $response = discoverAs(Tenant::factory()->create(), ['query' => 'unique two'])
        ->assertStatus(429);

    expect($response->headers->get('X-Quota-Scope'))->toBe('global');
});

it('reports upstream quota exhaustion as retryable rather than broken', function () {
    YouTubeApiFake::fakeQuotaExhausted();

    discoverAs(Tenant::factory()->create(), ['query' => 'unique three'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');
});

it('reports a bad api key as our problem, not the callers', function () {
    YouTubeApiFake::fakeBadKey();

    // Google uses 403 for both a bad key and exhausted quota. Only one of them
    // is worth retrying, and a misconfigured key is an operator error.
    discoverAs(Tenant::factory()->create(), ['query' => 'unique four'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'resolver_unavailable');
});

it('reports missing configuration as unavailable rather than invalid input', function () {
    config()->set('media.discovery.youtube_api_key', null);

    discoverAs(Tenant::factory()->create(), ['query' => 'unique five'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'resolver_unavailable');
});

it('serves a category feed', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);
    VideoCategory::factory()->create(['slug' => 'comedy', 'query_template' => 'funny shorts']);

    discoverAs(Tenant::factory()->create(), ['kind' => 'category', 'category' => 'comedy', 'query' => null])
        ->assertOk()
        ->assertJsonCount(1, 'videos');
});

it('rejects an unknown or inactive category', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);
    VideoCategory::factory()->inactive()->create(['slug' => 'retired']);

    discoverAs(Tenant::factory()->create(), ['kind' => 'category', 'category' => 'retired', 'query' => null])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_request');

    Http::assertNothingSent();
});

it('requires a query for a search', function () {
    discoverAs(Tenant::factory()->create(), ['query' => null])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_request');
});

it('requires the discover ability', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    $tenant = Tenant::factory()->create();
    $token = $tenant->createToken('t', [Tenant::ABILITY_RESOLVE])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/discover', ['kind' => 'search', 'query' => 'cats'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'unauthorized');
});

it('keeps separate cache entries per page token', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create())->assertOk();
    discoverAs(Tenant::factory()->create(), ['page_token' => 'CAUQAA'])->assertOk();

    // Page two is a different result set and must not be served page one.
    expect(YouTubeApiFake::dataApiCalls())->toBe(4);
});

it('returns a url a client can hand straight back to resolve', function () {
    // Without this the consumer has to know how to build a YouTube URL out of
    // an id, which is exactly the coupling this API exists to remove.
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    $response = discoverAs(Tenant::factory()->create())->assertOk();

    expect($response->json('videos.0.url'))->toBe('https://www.youtube.com/watch?v=aaaaaaaaaaa')
        ->and($response->json('videos.0.provider'))->toBe('youtube');
});

it('points an unknown category at the endpoint that lists them', function () {
    $response = discoverAs(Tenant::factory()->create(), [
        'kind' => 'category',
        'query' => null,
        'category' => 'no-such-feed',
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('/v1/categories');

    Http::assertNothingSent();
});
