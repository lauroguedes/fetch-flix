<?php

namespace App\Console\Commands\Tenant;

use App\Console\Commands\Tenant\Concerns\ChoosesTenant;
use App\Models\Tenant;
use App\Models\TenantStatus;
use Illuminate\Console\Command;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

/**
 * Restores a suspended tenant.
 *
 * Its existing tokens resume working immediately and unchanged — suspension
 * never touched them. Anything already revoked stays revoked.
 */
class ActivateTenantCommand extends Command
{
    use ChoosesTenant;

    protected $signature = 'tenant:activate {slug? : The tenant slug. Omit for the guided flow.}';

    protected $description = 'Reactivate a suspended tenant';

    public function handle(): int
    {
        $tenant = $this->argument('slug') === null
            ? $this->pick()
            : $this->tenantBySlug((string) $this->argument('slug'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        if ($tenant->isActive()) {
            $this->components->warn("[{$tenant->name}] is already active.");

            return self::SUCCESS;
        }

        $tenant->update(['status' => TenantStatus::Active]);

        $tokens = $tenant->tokens()->count();

        $this->components->info("[{$tenant->name}] is active again.");

        note(match (true) {
            $tokens === 0 => 'It holds no tokens, so it still cannot call the API. Issue one with `tenant:token`.',
            $tokens === 1 => 'Its one existing token works again immediately — no reissue needed.',
            default => "Its {$tokens} existing tokens work again immediately — no reissue needed.",
        });

        return self::SUCCESS;
    }

    private function pick(): ?Tenant
    {
        if (! $this->requireTerminal('tenant:activate')) {
            return null;
        }

        intro('Reactivate a tenant');

        return $this->pickTenant(
            label: 'Which application should be served again?',
            only: fn (Tenant $t) => ! $t->isActive(),
        );
    }
}
