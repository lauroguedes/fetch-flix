<?php

namespace App\Media\Listeners;

use App\Media\Events\ExtractorBroken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a broken extractor into one alert instead of a wave of silent failures.
 *
 * Logged at error with the stderr sample, because writing a new stderr pattern
 * requires seeing the actual text. Counted in a rolling window so that a single
 * odd failure is noise and a sustained run is an incident.
 */
class ReportExtractorBroken
{
    public const COUNTER = 'extractor:broken:count';

    public function handle(ExtractorBroken $event): void
    {
        $count = $this->increment();

        Log::error('yt-dlp extraction failed', [
            // An unmatched failure is the strongest signal that YouTube changed
            // under us, so it is called out explicitly.
            'matched_known_pattern' => $event->matched,
            'stderr' => mb_substr($event->stderr, 0, 2000),
            'count_last_hour' => $count,
            'yt_dlp_version' => config('media.yt_dlp.channel'),
        ]);
    }

    private function increment(): int
    {
        $key = self::COUNTER.':'.now()->format('Y-m-d-H');
        Cache::add($key, 0, 3600);

        return (int) Cache::increment($key);
    }

    public static function countThisHour(): int
    {
        return (int) (Cache::get(self::COUNTER.':'.now()->format('Y-m-d-H')) ?? 0);
    }
}
