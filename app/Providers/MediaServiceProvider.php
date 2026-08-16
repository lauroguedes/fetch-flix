<?php

namespace App\Providers;

use App\Api\UsageTracker;
use App\Media\Discovery\DimensionResolver;
use App\Media\Discovery\YouTubeDimensionResolver;
use App\Media\ProviderRegistry;
use App\Media\YtDlp\ProcessYtDlpRunner;
use App\Media\YtDlp\YtDlpRunner;
use Illuminate\Contracts\Foundation\Application;
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
}
