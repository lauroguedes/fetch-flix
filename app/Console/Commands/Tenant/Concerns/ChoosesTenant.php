<?php

namespace App\Console\Commands\Tenant\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

/**
 * Shared plumbing for the tenant commands.
 *
 * All of them take an optional slug: given one they run unattended, given none
 * they ask. Both halves of that are here so the four commands cannot drift
 * apart on how they find a tenant, how they describe one, or how they refuse
 * without a terminal.
 */
trait ChoosesTenant
{
    /**
     * Guard the guided path.
     *
     * Without a terminal every prompt falls through to its default and the
     * first required one throws, spraying a stack trace over a CI log.
     */
    protected function requireTerminal(string $command): bool
    {
        if ($this->input->isInteractive()) {
            return true;
        }

        $this->components->error("{$command} needs a terminal for the guided flow. Pass a tenant slug to run it unattended.");

        return false;
    }

    protected function tenantBySlug(string $slug): ?Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->components->error("No tenant with slug [{$slug}].");
        }

        return $tenant;
    }

    /**
     * Every tenant, each already carrying its token count.
     *
     * Counted in the one query rather than per row: `describeTenant()` needs
     * the number for every option it renders, and asking per tenant turns
     * opening a picker into one round trip per registered application.
     *
     * @return Collection<int, Tenant>
     */
    protected function allTenants(): Collection
    {
        return Tenant::query()->withCount('tokens')->orderBy('name')->get();
    }

    /**
     * @param  callable(Tenant): bool|null  $only
     */
    protected function pickTenant(string $label, ?callable $only = null): ?Tenant
    {
        $tenants = $this->allTenants();

        if ($only !== null) {
            $tenants = $tenants->filter($only);
        }

        if ($tenants->isEmpty()) {
            $this->components->error('No tenant matches. Run `tenant:token` to register one.');

            return null;
        }

        $slug = select(
            label: $label,
            options: $this->tenantOptions($tenants),
            scroll: 10,
        );

        return $tenants->firstWhere('slug', $slug);
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     * @return array<string, string>
     */
    protected function tenantOptions(Collection $tenants): array
    {
        return $tenants
            ->mapWithKeys(fn (Tenant $t) => [$t->slug => $this->describeTenant($t)])
            ->all();
    }

    protected function describeTenant(Tenant $tenant): string
    {
        $tokens = $tenant->tokens_count ?? $tenant->tokens()->count();
        $suffix = $tenant->isActive() ? '' : ' — suspended';

        return "{$tenant->name} ({$tenant->slug}) — {$tokens} ".Str::plural('token', $tokens).$suffix;
    }
}
