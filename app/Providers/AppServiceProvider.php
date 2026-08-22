<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
    }

    /**
     * Teach `artisan dev` about the queues this application actually uses.
     *
     * The framework default listens on `default` alone, which silently ignores
     * every job this service dispatches: resolves queue their re-extraction on
     * `resolve` and their transfer on `downloads`. The visible symptom is a
     * resolve that succeeds and a download that never becomes available, which
     * looks like a bug in the service rather than a missing listener.
     *
     * Order matters. queue:listen drains its queues left to right, so a backlog
     * of transfers cannot starve the cheap re-extractions that unblock a
     * waiting client.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::except('queue');

        DevCommands::artisan(
            'queue:listen --queue=resolve,downloads,default --tries=1 --timeout=0',
            // Not "queue": `except` filters by name at run time, so reusing the
            // default's name would exclude this replacement too.
            'queues',
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
