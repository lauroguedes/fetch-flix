<?php

namespace App\Console\Commands\Tenant;

use App\Console\Commands\Tenant\Concerns\ChoosesTenant;
use App\Models\Tenant;
use App\Models\TenantStatus;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Prompts\Elements\KeyValueList;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Onboards a consuming application and issues its API token.
 *
 * Deliberately a console command rather than an HTTP endpoint: there is no
 * self-serve signup for this service. Restricting issuance to an operator with
 * shell access is one of the cheapest reductions in abuse and legal exposure.
 *
 * Two ways in, both landing on the same code. Run it bare and it walks you
 * through registering a tenant and scoping its token. Pass a slug and it
 * behaves exactly as it always did, so provisioning scripts and CI keep
 * working unchanged.
 */
class IssueTenantTokenCommand extends Command
{
    use ChoosesTenant;

    protected $signature = 'tenant:token
        {slug? : The tenant slug. Omit for the guided flow.}
        {--name=default : A label for the token, shown when auditing or revoking}
        {--ability=* : Abilities to grant (resolve, discover). Defaults to both.}
        {--expires-in-days= : Expire this token sooner than the configured ceiling.}';

    protected $description = 'Register a tenant and issue its API token';

    public function handle(): int
    {
        return $this->argument('slug') === null
            ? $this->guided()
            : $this->direct();
    }

    /**
     * The original non-interactive contract, unchanged.
     *
     * Kept whole rather than folded into the guided flow: a deploy script that
     * pipes this command must never block on a prompt.
     */
    private function direct(): int
    {
        $tenant = $this->tenantBySlug((string) $this->argument('slug'));

        if ($tenant === null) {
            note('Run `tenant:token` with no arguments to register one.');

            return self::FAILURE;
        }

        $abilities = $this->option('ability') ?: Tenant::abilities();

        $unknown = array_diff($abilities, Tenant::abilities());

        if ($unknown !== []) {
            $this->components->error('Unknown abilities: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $days = $this->option('expires-in-days');

        if ($days !== null && (! is_numeric($days) || (float) $days <= 0)) {
            $this->components->error('--expires-in-days must be a positive number of days.');

            return self::FAILURE;
        }

        $expiresAt = $days === null ? null : now()->addDays((float) $days);

        return $this->issue($tenant, (string) $this->option('name'), $abilities, $expiresAt);
    }

    private function guided(): int
    {
        if (! $this->requireTerminal('tenant:token')) {
            return self::FAILURE;
        }

        intro('Issue an API token');

        $tenant = $this->chooseTenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        // Keys of an associative array are `int|string` to PHP, so they are
        // narrowed here rather than at every use.
        $abilities = array_map(strval(...), multiselect(
            label: 'What can this token do?',
            options: [
                Tenant::ABILITY_RESOLVE => 'resolve — turn a video URL into a downloadable file',
                Tenant::ABILITY_DISCOVER => 'discover — browse and search feeds',
            ],
            default: Tenant::abilities(),
            required: 'A token with no abilities can call nothing.',
            hint: 'Grant only what the consumer needs: a discovery-only client cannot spend resolver capacity.',
        ));

        $label = text(
            label: 'Label for this token',
            placeholder: 'ios-app',
            default: 'default',
            required: true,
            hint: 'Shown when auditing or revoking. Name it after the thing that will hold it.',
        );

        $expiresAt = $this->chooseExpiry();

        table(
            headers: ['Tenant', 'Label', 'Abilities', 'Expires'],
            rows: [[
                "{$tenant->name} ({$tenant->slug})",
                $label,
                implode(', ', $abilities),
                $this->describeExpiry($expiresAt),
            ]],
        );

        if (! confirm('Issue this token?')) {
            outro('Nothing was issued.');

            return self::SUCCESS;
        }

        return $this->issue($tenant, $label, $abilities, $expiresAt);
    }

    /**
     * Like the trait's picker, but with a way out: this is the one command that
     * can create the tenant it is asking about, so its list carries an extra
     * option no tenant can satisfy.
     */
    private function chooseTenant(): ?Tenant
    {
        $tenants = $this->allTenants();

        if ($tenants->isEmpty()) {
            note('No tenants registered yet. Adding the first one.');

            return $this->registerTenant();
        }

        $choice = select(
            label: 'Which application is this token for?',
            options: ['__new' => '+ Register a new application'] + $this->tenantOptions($tenants),
            scroll: 10,
        );

        if ($choice === '__new') {
            return $this->registerTenant();
        }

        $tenant = $tenants->firstWhere('slug', $choice);

        if ($tenant !== null && ! $tenant->isActive()) {
            warning("[{$tenant->name}] is suspended. Its tokens will be refused until it is reactivated.");

            if (! confirm('Issue anyway?', default: false)) {
                return null;
            }
        }

        return $tenant;
    }

    private function registerTenant(): Tenant
    {
        $name = text(
            label: 'Name of the consuming application',
            placeholder: 'Acme iOS',
            required: true,
            hint: 'A consuming application, not a person. There are no user accounts here.',
        );

        $slug = text(
            label: 'Slug',
            default: Str::slug($name),
            required: true,
            validate: fn (string $value) => match (true) {
                Str::slug($value) !== $value => 'Use lowercase letters, numbers and hyphens only.',
                ! Tenant::slugIsAvailable($value) => 'A tenant already uses that slug.',
                default => null,
            },
            hint: 'Used on the command line to identify this tenant later.',
        );

        $attributes = ['name' => $name, 'slug' => $slug, 'status' => TenantStatus::Active];

        if (confirm('Set per-tenant limits now?', default: false, hint: 'Otherwise the deployment defaults apply, and they can be changed later.')) {
            $attributes += $this->askLimits();
        }

        $tenant = Tenant::query()->create($attributes);

        $this->components->info("Registered [{$tenant->name}].");

        return $tenant;
    }

    /**
     * @return array<string, int|null>
     */
    private function askLimits(): array
    {
        return [
            'rate_limit_rpm' => $this->askNumber('Resolve requests per minute', 60),
            'discover_rpm' => $this->askNumber('Discovery requests per minute', 30),
            // The shared daily budget is ~10,000 units and a search costs 100,
            // so this is a slice of a pool every tenant draws from.
            'quota_units_daily' => $this->askNumber('Daily discovery quota units', 2000),
            'max_bytes' => $this->askNumber('Largest file to deliver, in bytes (0 for no tenant limit)', 0) ?: null,
            'max_duration_ms' => $this->askNumber('Longest media to accept, in milliseconds (0 for no tenant limit)', 0) ?: null,
        ];
    }

    private function askNumber(string $label, int $default): int
    {
        return (int) text(
            label: $label,
            default: (string) $default,
            required: true,
            validate: fn (string $value) => is_numeric($value) && (int) $value >= 0
                ? null
                : 'Enter a whole number of zero or more.',
        );
    }

    private function chooseExpiry(): ?CarbonInterface
    {
        $choice = select(
            label: 'When should it expire?',
            options: [
                'ceiling' => 'At the configured ceiling — '.$this->ceiling(),
                '90' => 'In 90 days',
                '30' => 'In 30 days',
                'custom' => 'A specific number of days',
            ],
            hint: 'There is no self-serve refresh: a consumer whose token expires needs a new one issued here.',
        );

        if ($choice === 'ceiling') {
            return null;
        }

        // Read as a float, like `--expires-in-days`: validating one way and
        // converting the other turned 0.5 into addDays(0), which prints a token
        // that has already expired.
        $days = $choice === 'custom'
            ? (float) text(
                label: 'Expire in how many days?',
                required: true,
                validate: fn (string $value) => is_numeric($value) && (float) $value > 0
                    ? null
                    : 'Enter a positive number of days.',
            )
            : (float) $choice;

        return now()->addDays($days);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function issue(Tenant $tenant, string $label, array $abilities, ?CarbonInterface $expiresAt): int
    {
        $token = $tenant->createToken($label, $abilities, $expiresAt);

        $this->newLine();

        callout(
            label: 'Token issued',
            content: [
                new KeyValueList([
                    'Tenant' => "{$tenant->name} ({$tenant->slug})",
                    'Label' => $label,
                    'Abilities' => implode(', ', $abilities),
                    'Expires' => $this->describeExpiry($expiresAt),
                ]),
                // On its own line and nothing else on it, so a double-click or
                // a drag selects the whole token and no punctuation.
                $token->plainTextToken,
            ],
            info: 'Copy it now',
        );

        warning('Only shown once — the database keeps a hash, not this value.');
        note("Use it as:  Authorization: Bearer {$token->plainTextToken}");

        return self::SUCCESS;
    }

    private function describeExpiry(?CarbonInterface $expiresAt): string
    {
        // The configured ceiling still applies, so "never" is never the answer.
        return $expiresAt?->toDayDateTimeString() ?? $this->ceiling().' (configured ceiling)';
    }

    private function ceiling(): string
    {
        $minutes = (int) config('sanctum.expiration', 0);

        return $minutes > 0
            ? now()->addMinutes($minutes)->toDayDateTimeString()
            : 'never — sanctum.expiration is unset';
    }
}
