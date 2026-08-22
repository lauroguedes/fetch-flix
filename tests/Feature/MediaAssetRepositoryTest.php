<?php

use App\Api\ErrorCode;
use App\Media\Data\AssetStatus;
use App\Media\Storage\AssetKey;
use App\Media\Storage\MediaAssetRepository;
use App\Models\MediaAsset;

function repository(): MediaAssetRepository
{
    return app(MediaAssetRepository::class);
}

function assetAttributes(string $videoId = 'dQw4w9WgXcQ'): array
{
    return [
        'provider' => 'youtube',
        'provider_video_id' => $videoId,
        'max_height' => 720,
        'audio_only' => false,
        'duration_ms' => 213000,
        'title' => 'Never Gonna Give You Up',
        'author' => 'Rick Astley',
    ];
}

it('registers a variant once', function () {
    $key = AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false);

    $first = repository()->register($key, assetAttributes());
    $second = repository()->register($key, assetAttributes());

    expect($second->id)->toBe($first->id)
        ->and($first->status)->toBe(AssetStatus::Pending)
        ->and(MediaAsset::query()->count())->toBe(1);
});

it('refreshes metadata on a variant that is not stored yet', function () {
    $key = AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false);

    repository()->register($key, assetAttributes());
    $updated = repository()->register($key, [...assetAttributes(), 'title' => 'Retitled']);

    expect($updated->fresh()->title)->toBe('Retitled');
});

it('leaves a stored variant untouched', function () {
    // The recorded size and dimensions describe a file that exists. They must
    // keep describing that file, not whatever the extractor last reported.
    $asset = MediaAsset::factory()->stored()->create();

    repository()->register(
        AssetKey::fromString($asset->asset_key),
        [...assetAttributes($asset->provider_video_id), 'title' => 'Retitled'],
    );

    expect($asset->fresh()->title)->not->toBe('Retitled');
});

it('lets exactly one worker claim a download', function () {
    $asset = MediaAsset::factory()->create();

    $first = repository()->claim($asset, maxAttempts: 3, staleAfter: 900);
    $second = repository()->claim($asset->fresh(), maxAttempts: 3, staleAfter: 900);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($asset->fresh()->status)->toBe(AssetStatus::Downloading)
        // Counted once, not twice: a lost race must be a complete no-op.
        ->and($asset->fresh()->attempts)->toBe(1);
});

it('reclaims a row abandoned by a dead worker', function () {
    $asset = MediaAsset::factory()->downloading()->create();
    $asset->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    // Without this, a single crashed worker would strand that video forever.
    expect(repository()->claim($asset, maxAttempts: 3, staleAfter: 900))->toBeTrue();
});

it('will not reclaim a download that is still alive', function () {
    $asset = MediaAsset::factory()->downloading()->create();

    expect(repository()->claim($asset, maxAttempts: 3, staleAfter: 900))->toBeFalse();
});

it('retries a failed download until the attempt ceiling', function () {
    $asset = MediaAsset::factory()->failed()->create(['attempts' => 2]);

    expect(repository()->claim($asset, maxAttempts: 3, staleAfter: 900))->toBeTrue()
        ->and($asset->fresh()->error_code)->toBeNull();

    repository()->markFailed($asset->fresh(), ErrorCode::DownloadFailed);

    expect(repository()->claim($asset->fresh(), maxAttempts: 3, staleAfter: 900))->toBeFalse();
});

it('records what was actually stored', function () {
    $asset = MediaAsset::factory()->create();
    repository()->claim($asset, maxAttempts: 3, staleAfter: 900);

    repository()->markStored($asset, [
        'disk' => 'media',
        'path' => 'youtube/ab/dQw4w9WgXcQ/18.mp4',
        'format_id' => '18',
        'bytes' => 1024,
        'mime_type' => 'video/mp4',
        'container' => 'mp4',
        'width' => 640,
        'height' => 360,
    ]);

    $stored = $asset->fresh();

    expect($stored->status)->toBe(AssetStatus::Stored)
        ->and($stored->isServable())->toBeTrue()
        ->and($stored->downloaded_at)->not->toBeNull()
        ->and($stored->error_code)->toBeNull();
});

it('does not call a stored row servable without a file behind it', function () {
    $asset = MediaAsset::factory()->stored()->create(['path' => null]);

    expect($asset->isServable())->toBeFalse();
});

it('throttles access stamps and leaves the claim heartbeat alone', function () {
    $asset = MediaAsset::factory()->stored()->create([
        'last_accessed_at' => now()->subMinutes(5),
        'updated_at' => now()->subHour(),
    ]);

    $updatedAt = $asset->updated_at;
    $accessedAt = $asset->last_accessed_at;

    repository()->touchAccess($asset);

    expect($asset->fresh()->last_accessed_at->timestamp)->toBe($accessedAt->timestamp);

    repository()->touchAccess($asset, throttle: 60);

    // Bumping updated_at here would look like a live worker to claim() and
    // block reclaim of a genuinely dead download.
    expect($asset->fresh()->last_accessed_at->timestamp)->toBeGreaterThan($accessedAt->timestamp)
        ->and($asset->fresh()->updated_at->timestamp)->toBe($updatedAt->timestamp);
});

it('knows when a file is still referenced by another variant', function () {
    // A 720p and a 1080p request both land on itag 18 and share one object.
    // Pruning the first must not delete the file the second needs.
    $shared = 'youtube/ab/dQw4w9WgXcQ/18.mp4';

    $a = MediaAsset::factory()->stored()->create(['path' => $shared, 'max_height' => 720]);
    $b = MediaAsset::factory()->stored()->create(['path' => $shared, 'max_height' => 1080]);

    expect(repository()->pathIsShared($a))->toBeTrue();

    $b->delete();

    expect(repository()->pathIsShared($a->fresh()))->toBeFalse();
});
