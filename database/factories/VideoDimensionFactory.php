<?php

namespace Database\Factories;

use App\Media\Data\DimensionSource;
use App\Models\VideoDimension;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoDimension>
 */
class VideoDimensionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'youtube',
            'provider_video_id' => Str::random(11),
            'width' => 1080,
            'height' => 1920,
            'source' => DimensionSource::OEmbed,
            'created_at' => now(),
        ];
    }

    public function landscape(): static
    {
        return $this->state(fn (array $attributes) => ['width' => 1280, 'height' => 720]);
    }
}
