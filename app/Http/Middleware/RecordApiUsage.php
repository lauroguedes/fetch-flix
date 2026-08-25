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
 * Terminable, so the insert lands after the response is sent and adds no
 * latency to a carefully budgeted request. Written here rather than dispatched
 * as a job so a backed-up queue cannot lose spend records, and in terminate()
 * so failed requests are captured too — exactly when usage matters most.
 */
class RecordApiUsage
{
    /** `X-Client: <platform>/<app version>`, e.g. `ios/1.4.0`. */
    public const CLIENT_HEADER = 'X-Client';

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
                ...$this->client($request),
                'quota_units' => $this->tracker->quotaUnits(),
                'cache_hit' => $this->tracker->wasCacheHit(),
                'status_code' => $response->getStatusCode(),
                'error_code' => $this->errorCode($response),
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Accounting must never fail a response that already went out.
            report($e);
        }
    }

    /**
     * Identify the calling app from the `X-Client` header.
     *
     * A header rather than a body field, so it attributes every endpoint the
     * same way and costs a client one line instead of a required object. The
     * API version is deliberately not carried here: the URI path already
     * states it.
     *
     * Read from the raw request, never from validated input, so a call that
     * failed validation is still attributed to the app that sent it — exactly
     * when knowing the version matters most. That makes the value untrusted,
     * hence the truncation.
     *
     * @return array{client_platform: string|null, client_app_version: string|null}
     */
    private function client(Request $request): array
    {
        $header = (string) $request->header(self::CLIENT_HEADER, '');

        // Only the first slash separates the two, so a version containing one
        // survives intact rather than being truncated at it.
        $parts = explode('/', trim($header), 2);

        return [
            'client_platform' => $this->clamp($parts[0], 32),
            'client_app_version' => $this->clamp($parts[1] ?? '', 32),
        ];
    }

    /**
     * Truncate to the column, and drop anything that is not printable ASCII.
     *
     * The charset filter is the part that matters. This value is attacker-
     * controlled and the old body field was validated before it got here; an
     * invalid UTF-8 sequence makes the insert throw, and the `catch` above
     * swallows it — so a client sending one malformed header would vanish from
     * the usage ledger entirely rather than merely being mislabelled.
     */
    private function clamp(string $value, int $limit): ?string
    {
        $value = trim((string) preg_replace('/[^\x20-\x7E]/', '', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
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
