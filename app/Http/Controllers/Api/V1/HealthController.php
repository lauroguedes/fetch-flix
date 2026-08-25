<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\RequestId;
use App\Media\Data\AssetStatus;
use App\Media\Discovery\QuotaGuard;
use App\Media\YtDlp\ExtractorReport;
use App\Models\ExtractorHealth;
use App\Models\MediaAsset;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Operational probe, in two depths.
 *
 * Unauthenticated it answers only the question a load balancer or uptime
 * monitor asks: is this service serving? That is deliberate. The detailed view
 * names the extractor version, the remaining discovery quota and the size of
 * the library — enough for an outsider to fingerprint the deployment and to
 * time a quota-exhaustion attack against a budget shared by every consumer.
 *
 * A valid tenant token unlocks the detail on the same URL, so monitoring keeps
 * one endpoint and simply presents credentials for the deeper view.
 */
class HealthController
{
    /**
     * Service health, at two depths
     *
     * Unauthenticated, it returns only `status`, `extractor.state` and
     * `storage.reachable` — what a load balancer or uptime monitor needs.
     *
     * Send a valid tenant token to the same URL to also get the extractor
     * version and recent failure counts, the remaining discovery quota, and
     * storage counts. That detail is withheld from anonymous callers because it
     * fingerprints the deployment and would let an outsider time an attack on
     * the shared discovery budget.
     *
     * `status` is `ok` or `degraded`; a `degraded` service still answers.
     */
    public function __invoke(Request $request, QuotaGuard $quota): JsonResponse
    {
        $latest = ExtractorHealth::query()->latest('checked_at')->first();

        $extractor = match (true) {
            $latest === null => 'unknown',
            $latest->ok => 'ok',
            default => 'broken',
        };

        $reachable = $this->storageReachable();

        $payload = [
            'status' => $extractor === 'broken' || ! $reachable ? 'degraded' : 'ok',
            'extractor' => ['state' => $extractor],
            'storage' => ['reachable' => $reachable],
            'request_id' => RequestId::current(),
        ];

        if (! $this->isTrusted()) {
            return response()->json($payload);
        }

        $payload['extractor'] += [
            'yt_dlp_version' => $latest?->yt_dlp_version,
            'checked_at' => $latest?->checked_at?->toIso8601ZuluString(),
            'failure_code' => $latest?->failure_code,
            'failures_last_hour' => ExtractorReport::breakagesThisHour(),
            // Decides whether to escalate to a PO token provider or move
            // resolver egress off the datacenter.
            'bot_checks_last_hour' => ExtractorReport::botChecksThisHour(),
        ];

        $payload['discovery'] = [
            'quota_remaining' => $quota->remainingGlobally(),
            'quota_resets_in' => $quota->secondsUntilReset(),
        ];

        $payload['storage'] += $this->storageDetail();

        return response()->json($payload);
    }

    /**
     * Whether the caller may see more than "is it up".
     *
     * Resolved off the guard rather than route middleware, because the route
     * has to stay reachable without a token at all.
     */
    private function isTrusted(): bool
    {
        $tenant = Auth::guard('sanctum')->user();

        return $tenant instanceof Tenant && $tenant->isActive();
    }

    /**
     * One existence probe rather than a write: a single HEAD, enough to catch
     * the failures that happen (wrong endpoint, expired credentials, deleted
     * bucket). Storage down is a full /resolve outage now that the bytes are
     * ours, so it belongs in the public status.
     */
    private function storageReachable(): bool
    {
        try {
            Storage::disk((string) config('media.storage.disk', 'media'))->exists('.probe');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Kept out of the public probe for two reasons: it names the deployment's
     * size, and it costs an aggregate over every stored row on an endpoint
     * anyone can call.
     *
     * @return array{driver: string|null, stored: int, pending: int, failed: int, bytes: int}
     */
    private function storageDetail(): array
    {
        $disk = (string) config('media.storage.disk', 'media');

        // One grouped pass rather than four aggregates.
        $counts = MediaAsset::query()
            ->selectRaw('status, count(*) as asset_count, coalesce(sum(bytes), 0) as bytes')
            ->groupBy('status')
            ->get()
            ->keyBy(fn (MediaAsset $row) => $row->status->value);

        $rows = fn (AssetStatus ...$statuses) => array_sum(array_map(
            fn (AssetStatus $status) => (int) ($counts[$status->value]->asset_count ?? 0),
            $statuses,
        ));

        return [
            'driver' => config("filesystems.disks.{$disk}.driver"),
            'stored' => $rows(AssetStatus::Stored),
            'pending' => $rows(AssetStatus::Pending, AssetStatus::Downloading),
            // A rising count means downloads are broken, which resolve alone
            // never shows: it keeps succeeding right up to the transfer.
            'failed' => $rows(AssetStatus::Failed),
            'bytes' => (int) ($counts[AssetStatus::Stored->value]->bytes ?? 0),
        ];
    }
}
