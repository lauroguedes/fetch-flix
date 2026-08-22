<?php

use App\Media\Data\AssetStatus;
use App\Media\Storage\MediaAssetRepository;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config()->set('media.storage.retention_days', 30);
});

function storedFor(int $daysAgo, array $attributes = []): MediaAsset
{
    $asset = MediaAsset::factory()->stored()->create([
        'last_accessed_at' => now()->subDays($daysAgo),
        ...$attributes,
    ]);

    Storage::disk('media')->put($asset->path, 'bytes');

    return $asset;
}

it('collects what nobody has asked for inside the window', function () {
    $cold = storedFor(45);
    $warm = storedFor(3);

    $this->artisan('media:prune')->assertSuccessful();

    expect(MediaAsset::query()->find($cold->id))->toBeNull()
        ->and(MediaAsset::query()->find($warm->id))->not->toBeNull();

    Storage::disk('media')->assertMissing($cold->path);
    Storage::disk('media')->assertExists($warm->path);
});

it('keeps a file another variant still points at', function () {
    // A 720p and a 1080p request both land on itag 18 and share one object.
    // Deleting the file with the first row would break the second.
    $shared = 'youtube/ab/dQw4w9WgXcQ/18.mp4';

    $cold = storedFor(45, ['path' => $shared, 'max_height' => 720]);
    storedFor(1, ['path' => $shared, 'max_height' => 1080]);

    $this->artisan('media:prune')->assertSuccessful();

    expect(MediaAsset::query()->find($cold->id))->toBeNull();
    Storage::disk('media')->assertExists($shared);
});

it('falls back to creation time for a file that was never served', function () {
    $never = MediaAsset::factory()->stored()->create([
        'last_accessed_at' => null,
        'created_at' => now()->subDays(45),
    ]);

    $this->artisan('media:prune')->assertSuccessful();

    expect(MediaAsset::query()->find($never->id))->toBeNull();
});

it('clears a stale failed row so a resolve can start over', function () {
    // While the row exists, every resolve keeps answering with a URL that leads
    // to an error.
    $failed = MediaAsset::factory()->failed()->create(['updated_at' => now()->subDays(3)]);
    $recent = MediaAsset::factory()->failed()->create();

    $this->artisan('media:prune')->assertSuccessful();

    expect(MediaAsset::query()->find($failed->id))->toBeNull()
        ->and(MediaAsset::query()->find($recent->id))->not->toBeNull();
});

it('leaves an in-flight download alone', function () {
    $pending = MediaAsset::factory()->downloading()->create(['created_at' => now()->subDays(90)]);

    $this->artisan('media:prune')->assertSuccessful();

    expect($pending->fresh()->status)->toBe(AssetStatus::Downloading);
});

it('reports without removing when asked to dry run', function () {
    $cold = storedFor(45);

    $this->artisan('media:prune --dry-run')->assertSuccessful();

    expect(MediaAsset::query()->find($cold->id))->not->toBeNull();
    Storage::disk('media')->assertExists($cold->path);
});

it('honours an overridden window', function () {
    $asset = storedFor(5);

    $this->artisan('media:prune --days=1')->assertSuccessful();

    expect(MediaAsset::query()->find($asset->id))->toBeNull();
});

it('counts deliveries so retention can tell what is worth keeping', function () {
    $asset = storedFor(0);

    app(MediaAssetRepository::class)->touchAccess($asset->fresh());
    app(MediaAssetRepository::class)->touchAccess($asset->fresh());

    // The access stamp is throttled; the counter is not.
    expect($asset->fresh()->serve_count)->toBe(2);
});
