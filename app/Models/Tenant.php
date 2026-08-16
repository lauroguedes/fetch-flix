<?php

namespace App\Models;

use App\Tenancy\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A consuming application, not a human account.
 *
 * Tenants authenticate with Sanctum tokens whose abilities act as scopes, so a
 * discovery-only consumer cannot spend resolver capacity and vice versa.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property int $rate_limit_rpm
 * @property int $discover_rpm
 * @property int $quota_units_daily
 * @property int|null $max_bytes
 * @property int|null $max_duration_ms
 * @property int $min_progressive_height
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'slug',
    'status',
    'rate_limit_rpm',
    'discover_rpm',
    'quota_units_daily',
    'max_bytes',
    'max_duration_ms',
    'min_progressive_height',
    'settings',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasApiTokens, HasFactory;

    public const ABILITY_RESOLVE = 'resolve';

    public const ABILITY_DISCOVER = 'discover';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status->canCallApi();
    }
}
