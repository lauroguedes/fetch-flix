<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * A consuming application, not a human account. Authenticates with Sanctum
 * tokens whose abilities act as scopes, so a discovery-only consumer cannot
 * spend resolver capacity and vice versa.
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
 * @property-read int|null $tokens_count Present only when loaded with `withCount('tokens')`.
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
class Tenant extends Model implements Authenticatable
{
    /** @use HasFactory<TenantFactory> */
    use HasApiTokens, HasFactory;

    public const ABILITY_RESOLVE = 'resolve';

    public const ABILITY_DISCOVER = 'discover';

    /**
     * Whether a slug is free to claim.
     *
     * Two tenants sharing one would make `tenant:token <slug>` ambiguous, and
     * an operator could not tell which application they had just credentialled.
     */
    public static function slugIsAvailable(string $slug): bool
    {
        return ! self::query()->where('slug', $slug)->exists();
    }

    /**
     * Every ability a token may carry, and the default grant.
     *
     * @return array<int, string>
     */
    public static function abilities(): array
    {
        return [self::ABILITY_RESOLVE, self::ABILITY_DISCOVER];
    }

    /**
     * Tenants are the resolved user of the `api` guard, so they must satisfy
     * its contract: anything reaching for `Auth::id()` otherwise fatals, as
     * Pail does on every log line.
     *
     * Implemented directly rather than with the Authenticatable trait, which
     * assumes a password column and a remember token. A tenant has neither.
     */
    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return $this->unsupportedCredential('password name');
    }

    public function getAuthPassword(): string
    {
        return $this->unsupportedCredential('password');
    }

    public function getRememberToken(): ?string
    {
        // Not throwing: sessions legitimately ask, and "no token" is truthful
        // for a consumer that sends a bearer token on every request.
        return null;
    }

    public function setRememberToken($value): void
    {
        //
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /**
     * @throws LogicException always
     */
    private function unsupportedCredential(string $what): never
    {
        throw new LogicException(
            "Tenants authenticate with Sanctum tokens and have no {$what}. ".
            'Reaching for one means a password-based guard has been pointed at the tenants provider.'
        );
    }

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
