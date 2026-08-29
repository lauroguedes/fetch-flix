<?php

use App\Models\VideoCategory;
use Database\Seeders\VideoCategorySeeder;

/**
 * The seeder is the source of truth for what this deployment serves, so
 * reseeding has to be a replacement rather than an addition.
 */
it('seeds the curated set in the operator ordering', function () {
    (new VideoCategorySeeder)->run();

    expect(VideoCategory::query()->active()->pluck('slug')->all())->toBe([
        'howto', 'curiosities', 'talks', 'history', 'technology', 'science', 'geography',
    ]);
});

it('retires a category it no longer serves instead of deleting it', function () {
    $dropped = VideoCategory::factory()->create(['slug' => 'gaming', 'is_active' => true]);

    (new VideoCategorySeeder)->run();

    // Kept, because cached_discoveries rows and past /discover responses still
    // carry the slug. `is_active` is what keeps it out of /categories and stops
    // `discover:warm` spending 100 units a refresh on it.
    expect($dropped->fresh()->is_active)->toBeFalse()
        ->and(VideoCategory::query()->where('slug', 'gaming')->exists())->toBeTrue();
});

it('is idempotent', function () {
    (new VideoCategorySeeder)->run();
    (new VideoCategorySeeder)->run();

    expect(VideoCategory::query()->count())->toBe(7);
});
