<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Database\Seeder;

/**
 * A development tenant to issue tokens against.
 *
 * Kept out of DatabaseSeeder deliberately: seeding a credential-bearing record
 * should be an explicit act, never something a production `migrate --seed`
 * does by accident.
 *
 *   php artisan db:seed --class=LocalDevSeeder
 *   php artisan tenant:token local-dev
 */
class LocalDevSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->firstOrCreate(
            ['slug' => 'local-dev'],
            [
                'name' => 'Local Development',
                'status' => TenantStatus::Active,
                'quota_units_daily' => 2000,
            ]
        );

        $this->call(VideoCategorySeeder::class);
    }
}
