<?php

use App\Media\YtDlp\YtDlpRunner;
use App\Models\Tenant;
use App\Models\UsageRecord;
use Tests\Support\FakeYtDlpRunner;
use Tests\Support\YouTubeApiFake;

beforeEach(function () {
    config()->set('media.discovery.youtube_api_key', 'test-key');
});

it('records the quota a discovery actually spent', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);
    $tenant = Tenant::factory()->create();

    discoverAs($tenant)->assertOk();

    $record = UsageRecord::query()->firstOrFail();

    // 100 for search.list plus 1 for videos.list.
    expect($record->quota_units)->toBe(101)
        ->and($record->cache_hit)->toBeFalse()
        ->and($record->status_code)->toBe(200)
        ->and($record->endpoint)->toBe('api.v1.discover')
        ->and($record->tenant_id)->toBe($tenant->id);
});

it('records a cache hit as costing nothing', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create())->assertOk();
    discoverAs(Tenant::factory()->create())->assertOk();

    $second = UsageRecord::query()->latest('id')->firstOrFail();

    expect($second->quota_units)->toBe(0)
        ->and($second->cache_hit)->toBeTrue();
});

it('records failures, which is when usage data matters most', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create(['quota_units_daily' => 50]))
        ->assertStatus(429);

    $record = UsageRecord::query()->firstOrFail();

    expect($record->status_code)->toBe(429)
        ->and($record->error_code)->toBe('rate_limited');
});

it('attributes usage to the tenant that spent it', function () {
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    discoverAs($a, ['query' => 'first query'])->assertOk();
    discoverAs($b, ['query' => 'second query'])->assertOk();

    expect(UsageRecord::query()->where('tenant_id', $a->id)->sum('quota_units'))->toBe(101)
        ->and(UsageRecord::query()->where('tenant_id', $b->id)->sum('quota_units'))->toBe(101);
});

it('records resolve calls as costing no discovery quota', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create())->assertOk();

    $record = UsageRecord::query()->firstOrFail();

    // Resolution never touches the Data API, so it must not appear to consume
    // the shared discovery allowance.
    expect($record->quota_units)->toBe(0)
        ->and($record->endpoint)->toBe('api.v1.resolve');
});

it('does not record anything for an unauthenticated request', function () {
    $this->postJson('/api/v1/discover', ['kind' => 'search', 'query' => 'cats'])
        ->assertStatus(403);

    expect(UsageRecord::query()->count())->toBe(0);
});

it('records which consuming app made the call', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create())->assertOk();

    $record = UsageRecord::query()->firstOrFail();

    // The question that actually comes up in an incident: is this every
    // consumer, or one app version that shipped a bad build?
    expect($record->client_platform)->toBe('ios')
        ->and($record->client_app_version)->toBe('1.4.2')
        ->and($record->client_api_version)->toBe('v1');
});

it('attributes a rejected request to the app that sent it', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    // Read from the raw request rather than validated input, because a failing
    // client is exactly when knowing its version matters most.
    resolveAs(Tenant::factory()->create(), ['prefer' => 'adaptive'])->assertStatus(422);

    $record = UsageRecord::query()->firstOrFail();

    expect($record->status_code)->toBe(422)
        ->and($record->error_code)->toBe('invalid_request')
        ->and($record->client_platform)->toBe('ios');
});

it('truncates an oversized client value rather than failing the write', function () {
    app()->instance(YtDlpRunner::class, FakeYtDlpRunner::withFixture('standard'));

    resolveAs(Tenant::factory()->create(), [
        'client' => ['platform' => str_repeat('x', 200), 'app_version' => '1', 'api_version' => 'v1'],
    ])->assertStatus(422);

    // The value is untrusted: an overlong field must not break accounting.
    expect(mb_strlen((string) UsageRecord::query()->firstOrFail()->client_platform))->toBe(32);
});

it('leaves client columns null for an endpoint that sends no client block', function () {
    config()->set('media.discovery.youtube_api_key', 'test-key');
    YouTubeApiFake::fake([['id' => 'aaaaaaaaaaa']]);

    discoverAs(Tenant::factory()->create())->assertOk();

    expect(UsageRecord::query()->firstOrFail()->client_platform)->toBeNull();
});
