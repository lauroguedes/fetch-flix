<?php

namespace App\Providers;

use App\Api\Exceptions\ApiException;
use App\Api\UsageTracker;
use App\Media\ProviderRegistry;
use App\Media\Storage\MediaDownloader;
use App\Media\Storage\YtDlpMediaDownloader;
use App\Media\YtDlp\ProcessYtDlpRunner;
use App\Media\YtDlp\YtDlpRunner;
use App\Models\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One accumulator per request, shared by everything that knows what a
        // call cost and the middleware that records it.
        $this->app->scoped(UsageTracker::class);

        // Keeps the test suite off the network, and leaves room for an
        // HTTP-backed sidecar runner.
        $this->app->bind(YtDlpRunner::class, ProcessYtDlpRunner::class);

        // Transfers run on a queue with a deadline in minutes, extraction on
        // the request with a deadline in seconds.
        $this->app->bind(MediaDownloader::class, YtDlpMediaDownloader::class);

        $this->app->singleton(ProviderRegistry::class, function (Application $app) {
            // `enabled` is optional: a provider may be declared and left off.
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
        $this->registerRateLimiters();
    }

    /**
     * Per-tenant burst control, distinct from the daily API quota: that
     * protects a shared upstream allowance, this protects our own resolver
     * capacity from one noisy consumer starving everybody else.
     */
    protected function registerRateLimiters(): void
    {
        // Authenticated buckets are keyed per tenant so one consumer's burst
        // cannot spend another's allowance; the unauthenticated ones fall back
        // to the address, which is all they have.
        $this->limit('resolve', fn (?Tenant $tenant) => $tenant === null ? 10 : $tenant->rate_limit_rpm, 'Too many resolve requests. Please slow down.');
        $this->limit('discover', fn (?Tenant $tenant) => $tenant === null ? 10 : $tenant->discover_rpm, 'Too many discovery requests. Please slow down.');

        // Costs no upstream quota and reads a handful of indexed rows, so the
        // ceiling is generous. It exists so listing the feeds cannot be used to
        // burn a tenant's discovery budget, which is the scarce one.
        $this->limit('categories', fn () => 60);

        // Unauthenticated, so keyed by address. Generous, because a local-disk
        // response answers each byte-range separately and a player seeking
        // through a video is a legitimate burst.
        //
        // The reason it exists at all: a request for an asset still downloading
        // holds a PHP worker for up to `wait_ms`, polling the row as it goes.
        // Without a ceiling, a few dozen concurrent requests for pending assets
        // exhaust the FPM pool and take the whole service down with them.
        $this->limit('stream', fn () => 120, 'Too many download requests. Please slow down.');

        // The probe is unauthenticated by design, and runs an aggregate query
        // plus a storage round trip. Cheap to call, not free to serve.
        $this->limit('health', fn () => 60);
    }

    /**
     * Register one named limiter.
     *
     * The 429 body is the reason this is shared: the framework's default is not
     * our error envelope, consumers only parse ours, and five hand-copied
     * closures is five places to forget.
     *
     * @param  callable(?Tenant): int  $perMinute  Receives null on the
     *                                             unauthenticated routes.
     */
    private function limit(string $name, callable $perMinute, ?string $message = null): void
    {
        RateLimiter::for($name, function (Request $request) use ($name, $perMinute, $message) {
            $user = $request->user();
            $tenant = $user instanceof Tenant ? $user : null;

            return Limit::perMinute($perMinute($tenant))
                ->by($name.':'.($tenant === null ? $request->ip() : $tenant->id))
                ->response(fn (Request $r, array $headers) => ApiException::rateLimited(
                    retryAfter: (int) ($headers['Retry-After'] ?? 60),
                    message: $message,
                )->toResponse($r));
        });
    }
}
