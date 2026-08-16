<?php

use App\Media\Data\DiscoveryPage;
use App\Media\Data\DiscoveryQuery;
use App\Media\Data\VideoCard;
use App\Media\Providers\YouTube\DataApiClient;

it('parses ISO-8601 durations the Data API returns', function (string $iso, ?int $ms) {
    expect(DataApiClient::isoDurationToMs($iso))->toBe($ms);
})->with([
    'seconds' => ['PT45S', 45000],
    'minutes and seconds' => ['PT1M2S', 62000],
    'exact minute' => ['PT3M', 180000],
    'hours' => ['PT1H2M3S', 3723000],
    // Live and upcoming broadcasts report a real zero, not a parse failure.
    'live stream' => ['P0D', 0],
    'garbage' => ['not-a-duration', null],
]);

it('collapses whitespace and case so equivalent searches share a cache entry', function () {
    $a = DiscoveryQuery::search('cats jumping', null, null);
    $b = DiscoveryQuery::search('  CATS   Jumping  ', null, null);

    // Each distinct key is a potential 100-unit call, so normalisation is what
    // keeps the allowance from fragmenting.
    expect($a->cacheKey())->toBe($b->cacheKey());
});

it('preserves word order, which changes upstream ranking', function () {
    expect(DiscoveryQuery::search('cats jumping', null, null)->cacheKey())
        ->not->toBe(DiscoveryQuery::search('jumping cats', null, null)->cacheKey());
});

it('excludes max_duration_ms from the cache key', function () {
    // One cached superset serves every duration variant across every tenant.
    // Including it would multiply 100-unit calls for no benefit.
    expect(DiscoveryQuery::search('cats', 30000, null)->cacheKey())
        ->toBe(DiscoveryQuery::search('cats', 180000, null)->cacheKey());
});

it('separates pages', function () {
    expect(DiscoveryQuery::search('cats', null, null)->cacheKey())
        ->not->toBe(DiscoveryQuery::search('cats', null, 'CAUQAA')->cacheKey());
});

it('filters a cached page by exact duration', function () {
    $page = new DiscoveryPage([
        new VideoCard('a', 'Short', 'Chan', 20000, null, 1080, 1920),
        new VideoCard('b', 'Long', 'Chan', 200000, null, 1080, 1920),
    ]);

    // The upstream `videoDuration=short` filter only means "under four
    // minutes", which is far too coarse for a short-form feed.
    $filtered = $page->withinDuration(30000);

    expect($filtered->videos)->toHaveCount(1)
        ->and($filtered->videos[0]->id)->toBe('a');
});

it('keeps videos whose duration is unknown rather than silently dropping them', function () {
    $page = new DiscoveryPage([new VideoCard('a', 'Unknown', 'Chan', null, null, 1080, 1920)]);

    expect($page->withinDuration(30000)->videos)->toHaveCount(1);
});
