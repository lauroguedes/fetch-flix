<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\UsageRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageRecord>
 */
class UsageRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'endpoint' => 'discover',
            'provider' => 'youtube',
            'client_platform' => 'ios',
            'client_app_version' => '1.4.2',
            'quota_units' => 0,
            'cache_hit' => true,
            'status_code' => 200,
            'error_code' => null,
            'duration_ms' => fake()->numberBetween(5, 500),
            'created_at' => now(),
        ];
    }
}
