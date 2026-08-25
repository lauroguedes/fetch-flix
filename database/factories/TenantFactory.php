<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'rate_limit_rpm' => 60,
            'discover_rpm' => 30,
            'quota_units_daily' => 2000,
            'max_bytes' => null,
            'max_duration_ms' => null,
            'min_progressive_height' => 0,
            'settings' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TenantStatus::Suspended,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TenantStatus::Active,
        ]);
    }

    /**
     * A tenant with no daily quota left to spend, for exhaustion tests.
     */
    public function withoutQuota(): static
    {
        return $this->state(fn (array $attributes) => [
            'quota_units_daily' => 0,
        ]);
    }
}
