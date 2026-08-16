<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Media\Discovery\QuotaGuard;
use App\Media\Listeners\ReportBotDetection;
use App\Media\Listeners\ReportExtractorBroken;
use App\Models\ExtractorHealth;
use Illuminate\Http\JsonResponse;

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

        return response()->json([
            'status' => $extractor === 'broken' ? 'degraded' : 'ok',
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
            'request_id' => RequestId::current(),
        ]);
    }
}
