<?php

namespace App\Console\Commands\Tenant;

use App\Console\Commands\Tenant\Concerns\ChoosesTenant;
use App\Models\Tenant;
use App\Models\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

/**
 * Cuts off a consuming application without destroying its credentials.
 *
 * Distinct from `tenant:revoke` on purpose. Suspension is the reversible lever:
 * `EnsureTenantActive` refuses every request at the edge while the tokens stay
 * intact, so an integration paused over a billing dispute or a runaway client
 * resumes with `tenant:activate` and no reissue. Revocation is the permanent
 * one, for a credential that has leaked.
 */
class SuspendTenantCommand extends Command
{
    use ChoosesTenant;

    protected $signature = 'tenant:suspend {slug? : The tenant slug. Omit for the guided flow.}';

    protected $description = 'Suspend a tenant, refusing its requests without revoking its tokens';

    public function handle(): int
    {
        $tenant = $this->argument('slug') === null
            ? $this->pick()
            : $this->tenantBySlug((string) $this->argument('slug'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        if (! $tenant->isActive()) {
            $this->components->warn("[{$tenant->name}] is already suspended.");

            return self::SUCCESS;
        }

        $tokens = $tenant->tokens()->count();

        if ($this->input->isInteractive()) {
            warning("This refuses every request from [{$tenant->name}] immediately. Its {$tokens} ".Str::plural('token', $tokens).' will keep working the moment it is reactivated.');

            if (! confirm('Suspend it?', default: false)) {
                outro('Nothing changed.');

                return self::SUCCESS;
            }
        }

        $tenant->update(['status' => TenantStatus::Suspended]);

        $this->components->info("[{$tenant->name}] is suspended.");
        note("Undo with: php artisan tenant:activate {$tenant->slug}");

        return self::SUCCESS;
    }

    private function pick(): ?Tenant
    {
        if (! $this->requireTerminal('tenant:suspend')) {
            return null;
        }

        intro('Suspend a tenant');

        return $this->pickTenant(
            label: 'Which application should stop being served?',
            only: fn (Tenant $t) => $t->isActive(),
        );
    }
}
