<?php

namespace App\Console\Commands\Tenant;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Issues an API token for a consuming application.
 *
 * Deliberately a console command rather than an HTTP endpoint: there is no
 * self-serve signup for this service. Restricting issuance to an operator with
 * shell access is one of the cheapest reductions in abuse and legal exposure.
 */
class IssueTenantTokenCommand extends Command
{
    protected $signature = 'tenant:token
        {slug : The tenant slug}
        {--name=default : A label for the token, shown when auditing or revoking}
        {--ability=* : Abilities to grant (resolve, discover). Defaults to both.}';

    protected $description = 'Issue an API token for a tenant';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->components->error("No tenant with slug [{$this->argument('slug')}].");

            return self::FAILURE;
        }

        $abilities = $this->option('ability') ?: [Tenant::ABILITY_RESOLVE, Tenant::ABILITY_DISCOVER];

        $unknown = array_diff($abilities, [Tenant::ABILITY_RESOLVE, Tenant::ABILITY_DISCOVER]);

        if ($unknown !== []) {
            $this->components->error('Unknown abilities: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $token = $tenant->createToken($this->option('name'), $abilities);

        $this->components->info("Token issued for [{$tenant->name}].");
        $this->components->twoColumnDetail('Abilities', implode(', ', $abilities));
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->components->warn('This value is not recoverable. Store it now.');

        return self::SUCCESS;
    }
}
