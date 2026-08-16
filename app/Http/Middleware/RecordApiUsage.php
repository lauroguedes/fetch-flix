<?php

namespace App\Http\Middleware;

use App\Api\UsageTracker;
use App\Models\Tenant;
use App\Models\UsageRecord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes the billing and analytics ledger.
 *
 * Terminable, so the insert happens after the response has been sent to the
 * client and never adds latency to the request the plan budgets so carefully.
 * Writing it here rather than dispatching a job keeps the ledger lossless: a
 * queue that is down or backed up must not silently lose spend records.
 *
 * Recording in terminate() also means failed requests are captured, which is
 * exactly when usage data matters most.
 */
class RecordApiUsage
{
    public function __construct(
        private readonly UsageTracker $tracker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tracker->reset();
        $request->attributes->set('usage.started_at', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $tenant = $request->user();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $startedAt = $request->attributes->get('usage.started_at');
        $durationMs = is_int($startedAt) || is_float($startedAt)
            ? (int) ((hrtime(true) - $startedAt) / 1_000_000)
            : null;

        try {
            UsageRecord::query()->create([
                'tenant_id' => $tenant->id,
                'endpoint' => $request->route()?->getName() ?? $request->path(),
                'provider' => $this->tracker->provider(),
                'quota_units' => $this->tracker->quotaUnits(),
                'cache_hit' => $this->tracker->wasCacheHit(),
                'status_code' => $response->getStatusCode(),
                'error_code' => $this->errorCode($response),
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Accounting must never turn a served response into a failed one.
            // The response has already gone out; all we can do is report.
            report($e);
        }
    }

    private function errorCode(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && is_string($decoded['code'] ?? null)
            ? $decoded['code']
            : null;
    }
}
