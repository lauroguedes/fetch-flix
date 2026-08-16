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
 */
class VideoCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'trending', 'label' => 'Trending', 'query_template' => 'trending shorts'],
            ['slug' => 'comedy', 'label' => 'Comedy', 'query_template' => 'funny shorts'],
            ['slug' => 'music', 'label' => 'Music', 'query_template' => 'music shorts'],
            ['slug' => 'sports', 'label' => 'Sports', 'query_template' => 'sports highlights shorts'],
            ['slug' => 'food', 'label' => 'Food', 'query_template' => 'cooking shorts'],
            ['slug' => 'animals', 'label' => 'Animals', 'query_template' => 'cute animal shorts'],
            ['slug' => 'gaming', 'label' => 'Gaming', 'query_template' => 'gaming shorts'],
            ['slug' => 'science', 'label' => 'Science', 'query_template' => 'science facts shorts'],
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
    }
}
