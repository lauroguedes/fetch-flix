<?php

namespace App\Tenancy;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function canCallApi(): bool
    {
        return $this === self::Active;
    }
}
