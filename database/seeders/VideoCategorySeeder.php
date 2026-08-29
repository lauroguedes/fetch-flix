<?php

namespace Database\Seeders;

use App\Models\VideoCategory;
use Illuminate\Database\Seeder;

/**
 * The curated feeds this deployment serves.
 *
 * Categories are server-side so their meaning can be retuned without a client
 * release, and so the number of distinct searches stays small: each one is
 * warmed on a schedule and costs 100 units per refresh, against an allowance of
 * 10,000 a day for the whole project.
 *
 * This list is authoritative. A slug that disappears from it is retired rather
 * than deleted, because `cached_discoveries` rows and `/discover` responses
 * still reference it, and `is_active` is what keeps it out of `/categories`.
 */
class VideoCategorySeeder extends Seeder
{
    public function run(): void
    {
        /*
         * The templates keep their short-form bias on purpose: `/discover`
         * filters at 180000ms unless a caller says otherwise, so a template
         * that mostly returns half-hour talks or full documentaries would
         * serve a feed that looks empty.
         */
        $categories = [
            ['slug' => 'howto', 'label' => 'How-to', 'query_template' => 'how to tutorial shorts'],
            ['slug' => 'curiosities', 'label' => 'Curiosities', 'query_template' => 'amazing facts shorts'],
            ['slug' => 'talks', 'label' => 'Talks', 'query_template' => 'inspiring talk shorts'],
            ['slug' => 'history', 'label' => 'History', 'query_template' => 'history facts shorts'],
            ['slug' => 'technology', 'label' => 'Technology', 'query_template' => 'technology explained shorts'],
            ['slug' => 'science', 'label' => 'Science', 'query_template' => 'science facts shorts'],
            ['slug' => 'geography', 'label' => 'Geography', 'query_template' => 'geography facts shorts'],
        ];

        foreach ($categories as $index => $category) {
            VideoCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'label' => $category['label'],
                    'provider' => 'youtube',
                    'query_template' => $category['query_template'],
                    'region_code' => 'US',
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }

        // Retire whatever this deployment used to serve. Without it a reseed
        // adds the new feeds and leaves the old ones listed and warmable,
        // spending 100 units a refresh on a category nobody curates any more.
        VideoCategory::query()
            ->whereNotIn('slug', array_column($categories, 'slug'))
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
