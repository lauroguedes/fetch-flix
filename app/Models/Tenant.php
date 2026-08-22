<?php

namespace App\Models;

use App\Tenancy\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

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
class Tenant extends Model implements Authenticatable
{
    /** @use HasFactory<TenantFactory> */
    use HasApiTokens, HasFactory;

    public const ABILITY_RESOLVE = 'resolve';

    public const ABILITY_DISCOVER = 'discover';

    /**
     * Tenants are the resolved user of the `api` guard, so they have to satisfy
     * the contract the guard is typed against. Anything that reaches for
     * `Auth::id()` during a request otherwise fatals: Pail does exactly that on
     * every log line, which turned any logged error on an authenticated request
     * into a 500 with the real cause buried underneath.
     *
     * Implemented directly rather than by using the Authenticatable trait,
     * because that trait assumes a password column and a remember token. A
     * tenant has neither, and claiming otherwise would be a lie that only
     * surfaces the first time something tries to use them.
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
        // Not throwing: sessions legitimately ask, and "no token" is a truthful
        // answer for a consumer that authenticates with a bearer token on every
        // request.
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
