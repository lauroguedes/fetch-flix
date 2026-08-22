<?php

use App\Models\CachedDiscovery;
use App\Models\ExtractorHealth;
use App\Models\UsageRecord;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
|
| yt-dlp breaks whenever YouTube changes and discovery quota is a shared daily
| budget, so both are handled on a schedule rather than discovered during an
| incident.
|
*/

// Detect a broken extractor before users do.
Schedule::command('yt-dlp:selftest --quiet-success')
    ->hourly()
    ->withoutOverlapping();

// Stay current with YouTube's weekly changes, but only keep a build that
// passes the self-test. Running nightly is safe precisely because of that
// verification step.
Schedule::command('yt-dlp:update')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->runInBackground();

// Category feeds are the predictable part of discovery, so they are paid for
// off-peak from a reserved budget rather than by whoever hits a cold cache.
Schedule::command('discover:warm')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Retention. Enforced rather than left to somebody remembering: what this
| service keeps about requesters is a stated part of its privacy posture.
*/

// Storage is the one part of this service with a bill attached, so an
// uncollected library is a cost that grows on its own.
Schedule::command('media:prune')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::call(function () {
    UsageRecord::query()->where('created_at', '<', now()->subDays(90))->delete();
    CachedDiscovery::query()->where('expires_at', '<', now()->subDays(2))->delete();
    ExtractorHealth::query()->where('checked_at', '<', now()->subDays(30))->delete();
})->daily()->name('prune-retention')->withoutOverlapping();
