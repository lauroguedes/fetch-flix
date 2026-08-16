<?php

namespace Database\Factories;

use App\Models\VideoCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoCategory>
 */
class VideoCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->word();

        return [
            'slug' => Str::slug($label),
            'label' => Str::title($label),
            'provider' => 'youtube',
            'query_template' => $label.' shorts',
            'region_code' => 'US',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
