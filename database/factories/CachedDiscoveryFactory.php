<?php

namespace Database\Factories;

use App\Models\CachedDiscovery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CachedDiscovery>
 */
class CachedDiscoveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cache_key' => 'discover:search:'.Str::random(16).':',
            'kind' => 'search',
            'provider' => 'youtube',
            'payload' => ['videos' => []],
            'next_page_token' => null,
            'quota_units_spent' => 100,
            'fetched_at' => now(),
            'expires_at' => now()->addHours(6),
        ];
    }

    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'fetched_at' => now()->subDay(),
            'expires_at' => now()->subHour(),
        ]);
    }
}
