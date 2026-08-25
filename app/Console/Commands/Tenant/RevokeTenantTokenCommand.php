<?php

namespace App\Console\Commands\Tenant;

use App\Console\Commands\Tenant\Concerns\ChoosesTenant;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

/**
 * Withdraws a token that has been issued.
 *
 * The counterpart to `tenant:token`, and the only thing that answers a leaked
 * credential. `sanctum:prune-expired` is not a substitute: it deletes tokens
 * that have *already* expired, which is exactly the set that is no longer
 * dangerous.
 *
 * Revocation is immediate. Sanctum resolves a bearer token by looking the row
 * up on every request, so deleting it ends the next call, not the next hour.
 */
class RevokeTenantTokenCommand extends Command
{
    use ChoosesTenant;

    protected $signature = 'tenant:revoke
        {slug? : The tenant slug. Omit for the guided flow.}
        {--id=* : Token ids to revoke, as shown by the guided flow.}
        {--all : Revoke every token this tenant holds.}';

    protected $description = 'Revoke an issued API token';

    public function handle(): int
    {
        return $this->argument('slug') === null
            ? $this->guided()
            : $this->direct();
    }

    private function direct(): int
    {
        $tenant = $this->tenantBySlug((string) $this->argument('slug'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        /** @var array<int, string> $ids */
        $ids = $this->option('id');

        if (! $this->option('all') && $ids === []) {
            $this->components->error('Pass --id=<id> (repeatable) or --all. Run `tenant:revoke` with no arguments to pick from a list.');

            return self::FAILURE;
        }

        $tokens = $this->option('all')
            ? $tenant->tokens()->get()
            : $tenant->tokens()->whereIn('id', $ids)->get();

        if ($tokens->isEmpty()) {
            $this->components->error('No matching token. Nothing was revoked.');

            return self::FAILURE;
        }

        // Deleting a credential cannot be undone and there is no self-serve
        // reissue, so even the slug-driven path asks when someone is watching.
        // A script passes --no-interaction and is never blocked.
        if ($this->input->isInteractive()) {
            table(
                headers: ['Id', 'Label', 'Last used'],
                rows: $tokens->map(fn (PersonalAccessToken $t) => [
                    (string) $t->id,
                    $t->name,
                    $t->last_used_at?->diffForHumans() ?? 'never',
                ])->all(),
            );

            if (! confirm('Revoke '.$this->tokenCount($tokens->count())." for [{$tenant->name}]?", default: false)) {
                outro('Nothing was revoked.');

                return self::SUCCESS;
            }
        }

        return $this->revoke($tenant, $tokens);
    }

    private function guided(): int
    {
        if (! $this->requireTerminal('tenant:revoke')) {
            return self::FAILURE;
        }

        intro('Revoke an API token');

        $tenant = $this->pickTenant(
            label: 'Whose token are you revoking?',
            only: fn (Tenant $t) => ($t->tokens_count ?? 0) > 0,
        );

        if ($tenant === null) {
            return self::FAILURE;
        }

        $tokens = $tenant->tokens()->orderByDesc('created_at')->get();

        table(
            headers: ['Id', 'Label', 'Abilities', 'Created', 'Last used', 'Expires'],
            rows: $tokens->map(fn (PersonalAccessToken $t) => [
                (string) $t->id,
                $t->name,
                implode(', ', $t->abilities ?? []),
                $t->created_at?->toDayDateTimeString() ?? '—',
                // The column that tells a dormant token from a live one, and so
                // which of several is safe to withdraw.
                $t->last_used_at?->diffForHumans() ?? 'never',
                $t->expires_at?->toDayDateTimeString() ?? '—',
            ])->all(),
        );

        $chosen = array_map(intval(...), multiselect(
            label: 'Which tokens should stop working?',
            options: $tokens
                ->mapWithKeys(fn (PersonalAccessToken $t) => [
                    $t->id => "#{$t->id} · {$t->name} · last used ".($t->last_used_at?->diffForHumans() ?? 'never'),
                ])
                ->all(),
            required: 'Select at least one, or press Ctrl+C to leave everything alone.',
            scroll: 10,
        ));

        $selected = $tokens->whereIn('id', $chosen);

        if (! confirm('Revoke '.$this->tokenCount($selected->count()).' now?', default: false)) {
            outro('Nothing was revoked.');

            return self::SUCCESS;
        }

        return $this->revoke($tenant, $selected);
    }

    /**
     * @param  Collection<int, PersonalAccessToken>  $tokens
     */
    private function revoke(Tenant $tenant, Collection $tokens): int
    {
        foreach ($tokens as $token) {
            $this->components->twoColumnDetail("#{$token->id} {$token->name}", '<fg=red>revoked</>');
        }

        // One statement, so a partial failure cannot leave half a set revoked
        // while the operator has already been told all of them were.
        $tenant->tokens()->whereKey($tokens->modelKeys())->delete();

        $remaining = $tenant->tokens()->count();

        $this->components->info($this->tokenCount($tokens->count())." revoked for [{$tenant->name}].");

        note($remaining === 0
            ? 'This tenant now holds no tokens and cannot call the API.'
            : $this->tokenCount($remaining).' still active for this tenant.');

        return self::SUCCESS;
    }

    private function tokenCount(int $count): string
    {
        return $count.' '.Str::plural('token', $count);
    }
}
