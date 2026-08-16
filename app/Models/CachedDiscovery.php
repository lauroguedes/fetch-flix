<?php

namespace App\Models;

use Database\Factories\CachedDiscoveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $cache_key
 * @property string $kind
 * @property string $provider
 * @property array<string, mixed> $payload
 * @property string|null $next_page_token
 * @property int $quota_units_spent
 * @property Carbon $fetched_at
 * @property Carbon $expires_at
 */
#[Fillable([
    'cache_key', 'kind', 'provider', 'payload',
    'next_page_token', 'quota_units_spent', 'fetched_at', 'expires_at',
])]
class CachedDiscovery extends Model
{
    /** @use HasFactory<CachedDiscoveryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isFresh(): bool
    {
        return $this->expires_at->isFuture();
    }
}
