<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Media\Data\AssetStatus;
use App\Media\Discovery\QuotaGuard;
use App\Media\Listeners\ReportBotDetection;
use App\Media\Listeners\ReportExtractorBroken;
use App\Models\ExtractorHealth;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Operational probe.
 *
 * Unauthenticated on purpose: it must stay reachable when token issuance or the
 * database is the thing that is broken, and it exposes no tenant data. Only
 * aggregate service state.
 */
class HealthController extends Controller
{
    public function __invoke(QuotaGuard $quota): JsonResponse
    {
        $latest = ExtractorHealth::query()->latest('checked_at')->first();

        $extractor = match (true) {
            $latest === null => 'unknown',
            $latest->ok => 'ok',
            default => 'broken',
        };

        $botChecks = ReportBotDetection::rateThisHour();
        $storage = $this->storage();

        return response()->json([
            'status' => $extractor === 'broken' || ! $storage['reachable'] ? 'degraded' : 'ok',
            'extractor' => [
                'state' => $extractor,
                'yt_dlp_version' => $latest?->yt_dlp_version,
                'checked_at' => $latest?->checked_at?->toIso8601ZuluString(),
                'failure_code' => $latest?->failure_code,
                'failures_last_hour' => ReportExtractorBroken::countThisHour(),
                // The number that decides whether to escalate to a PO token
                // provider or move resolver egress off the datacenter.
                'bot_checks_last_hour' => $botChecks,
            ],
            'discovery' => [
                'quota_remaining' => $quota->remainingGlobally(),
                'quota_resets_in' => $quota->secondsUntilReset(),
            ],
            'storage' => $storage,
            'request_id' => RequestId::current(),
        ]);
    }

    /**
     * State of the disk the service now serves media from.
     *
     * Reachability is checked with a single existence probe rather than a
     * write: it is one HEAD request, and it is enough to catch the failures
     * that actually happen — wrong endpoint, expired credentials, deleted
     * bucket. Storage being down is a full outage of /resolve now that the
     * bytes are ours, so it belongs in the top-level status.
     *
     * @return array{driver: string|null, reachable: bool, stored: int, pending: int, failed: int, bytes: int}
     */
    private function storage(): array
    {
        $disk = (string) config('media.storage.disk', 'media');

        try {
            Storage::disk($disk)->exists('.probe');
            $reachable = true;
        } catch (Throwable) {
            $reachable = false;
        }

        return [
            'driver' => config("filesystems.disks.{$disk}.driver"),
            'reachable' => $reachable,
            'stored' => MediaAsset::query()->where('status', AssetStatus::Stored)->count(),
            'pending' => MediaAsset::query()->whereIn('status', [AssetStatus::Pending, AssetStatus::Downloading])->count(),
            // A rising count here is the signal that downloads are broken,
            // which resolve alone would not show: resolve keeps succeeding and
            // the failure only surfaces when a client tries to download.
            'failed' => MediaAsset::query()->where('status', AssetStatus::Failed)->count(),
            'bytes' => (int) MediaAsset::query()->where('status', AssetStatus::Stored)->sum('bytes'),
        ];
    }
}
