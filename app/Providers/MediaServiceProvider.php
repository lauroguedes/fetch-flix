<?php

namespace App\Providers;

use App\Api\Exceptions\ApiException;
use App\Api\UsageTracker;
use App\Media\Delivery\MediaDelivery;
use App\Media\Delivery\PassthroughDelivery;
use App\Media\Delivery\StorageDelivery;
use App\Media\Discovery\DimensionResolver;
use App\Media\Discovery\YouTubeDimensionResolver;
use App\Media\Events\BotDetected;
use App\Media\Events\ExtractorBroken;
use App\Media\Listeners\ReportBotDetection;
use App\Media\Listeners\ReportExtractorBroken;
use App\Media\ProviderRegistry;
use App\Media\Storage\MediaDownloader;
use App\Media\Storage\YtDlpMediaDownloader;
use App\Media\YtDlp\ProcessYtDlpRunner;
use App\Media\YtDlp\YtDlpRunner;
use App\Models\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One accumulator per request, shared by everything that knows what a
        // call cost and by the middleware that records it.
        $this->app->scoped(UsageTracker::class);

        // The seam that keeps the test suite off the network and leaves room to
        // swap in an HTTP-backed sidecar runner without touching callers.
        $this->app->bind(YtDlpRunner::class, ProcessYtDlpRunner::class);

        // The transfer seam, separate from the extraction one: downloads run on
        // a queue with a deadline in minutes, extraction on the request with a
        // deadline in seconds.
        $this->app->bind(MediaDownloader::class, YtDlpMediaDownloader::class);

        // A binding rather than a conditional, so the pass-through path stays
        // executable and testable instead of decaying into an escape hatch
        // nobody has run in months.
        $this->app->bind(MediaDelivery::class, fn (Application $app) => config('media.delivery') === 'passthrough'
            ? $app->make(PassthroughDelivery::class)
            : $app->make(StorageDelivery::class));

        // The base class is the terminal rung on its own; this puts the
        // database and oEmbed lookups in front of it.
        $this->app->bind(DimensionResolver::class, YouTubeDimensionResolver::class);

        $this->app->singleton(ProviderRegistry::class, function (Application $app) {
            // `enabled` is optional: a deployment may declare a provider and
            // leave it switched off by omission.
            /** @var array<string, array{enabled?: bool, class: class-string}> $configured */
            $configured = config('media.providers', []);

            $providers = [];

            foreach ($configured as $definition) {
                if (! ($definition['enabled'] ?? false)) {
                    continue;
                }

                $providers[] = $app->make($definition['class']);
            }

            return new ProviderRegistry($providers);
        });
    }

    public function boot(): void
    {
        Event::listen(ExtractorBroken::class, ReportExtractorBroken::class);
        Event::listen(BotDetected::class, ReportBotDetection::class);

        $this->registerRateLimiters();
    }

    /**
     * Per-tenant burst control.
     *
     * Distinct from the daily API quota: that protects a shared upstream
     * allowance, this protects our own resolver capacity from a single noisy
     * consumer. Extraction is expensive and largely serial, so one tenant
     * looping requests could starve everyone else without this.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('resolve', function (Request $request) {
            $tenant = $request->user();
            $perMinute = $tenant instanceof Tenant ? $tenant->rate_limit_rpm : 10;

            return Limit::perMinute($perMinute)
                ->by('resolve:'.($tenant instanceof Tenant ? $tenant->id : $request->ip()))
                // The framework's default 429 body is not our error envelope,
                // and consumers only know how to parse ours.
                ->response(fn (Request $r, array $headers) => ApiException::rateLimited(
                    retryAfter: (int) ($headers['Retry-After'] ?? 60),
                    message: 'Too many resolve requests. Please slow down.',
                )->toResponse($r));
        });

        RateLimiter::for('discover', function (Request $request) {
            $tenant = $request->user();
            $perMinute = $tenant instanceof Tenant ? $tenant->discover_rpm : 10;

            return Limit::perMinute($perMinute)
                ->by('discover:'.($tenant instanceof Tenant ? $tenant->id : $request->ip()))
                ->response(fn (Request $r, array $headers) => ApiException::rateLimited(
                    retryAfter: (int) ($headers['Retry-After'] ?? 60),
                    message: 'Too many discovery requests. Please slow down.',
                )->toResponse($r));
        });
    }
}
