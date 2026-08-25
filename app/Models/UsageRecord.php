<?php

namespace App\Models;

use Database\Factories\UsageRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $endpoint
 * @property string|null $provider
 * @property string|null $client_platform
 * @property string|null $client_app_version
 * @property int $quota_units
 * @property bool $cache_hit
 * @property int $status_code
 * @property string|null $error_code
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
#[Fillable([
    'tenant_id', 'endpoint', 'provider',
    'client_platform', 'client_app_version',
    'quota_units',
    'cache_hit', 'status_code', 'error_code', 'duration_ms', 'created_at',
])]
class UsageRecord extends Model
{
    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cache_hit' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
