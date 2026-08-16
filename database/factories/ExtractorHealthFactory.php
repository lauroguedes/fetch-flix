<?php

namespace Database\Factories;

use App\Models\ExtractorHealth;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtractorHealth>
 */
class ExtractorHealthFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'youtube',
            'yt_dlp_version' => '2026.07.04',
            'ok' => true,
            'failure_code' => null,
            'notes' => null,
            'duration_ms' => 1500,
            'checked_at' => now(),
        ];
    }

    public function broken(): static
    {
        return $this->state(fn (array $attributes) => [
            'ok' => false,
            'failure_code' => 'resolver_unavailable',
            'notes' => 'nsig extraction failed',
        ]);
    }
}
