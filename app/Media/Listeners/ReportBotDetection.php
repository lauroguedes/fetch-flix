<?php

namespace App\Media\Listeners;

use App\Media\Events\BotDetected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tracks "Sign in to confirm you're not a bot" separately from other extractor
 * failures.
 *
 * The remedy is different: this is about where our traffic originates, not
 * about the binary being out of date. The rate of these decides whether to
 * escalate to a PO token provider or move resolver egress off the datacenter,
 * so it needs its own number rather than being buried in a general error count.
 */
class ReportBotDetection
{
    public const COUNTER = 'extractor:botcheck:count';

    public function handle(BotDetected $event): void
    {
        $count = $this->increment();

        Log::warning('YouTube served a bot check', [
            'count_last_hour' => $count,
            'stderr' => mb_substr($event->stderr, 0, 500),
        ]);
    }

    private function increment(): int
    {
        $key = self::COUNTER.':'.now()->format('Y-m-d-H');
        Cache::add($key, 0, 3600);

        return (int) Cache::increment($key);
    }

    public static function rateThisHour(): int
    {
        return (int) (Cache::get(self::COUNTER.':'.now()->format('Y-m-d-H')) ?? 0);
    }
}
