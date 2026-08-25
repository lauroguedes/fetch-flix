<?php

namespace App\Models;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function canCallApi(): bool
    {
        return $this === self::Active;
    }
}
