<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rank checks run overnight in the market they describe: the application clock
// is UTC, so the time is pinned to Tokyo rather than left to drift with it.
Schedule::command('rankings:fetch-daily')
    ->dailyAt('02:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer();

// The month's report is built once the month is over, an hour after the last
// night's rank checks have had time to land in it.
Schedule::command('reports:generate-monthly')
    ->monthlyOn(1, '03:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer();

// Reviews arrive at any hour, so they are pulled down nightly, after the rank
// checks and before the day starts in the market they belong to.
Schedule::command('gbp:sync-reviews')
    ->dailyAt('03:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer();

// Google's performance figures move slowly and each sweep asks for a window
// that overlaps the last, so weekly is enough.
Schedule::command('gbp:sync-performance')
    ->weeklyOn(1, '04:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer();

// Tokens are kept warm between calls rather than only renewed on demand, so a
// connection that has gone quiet is found here rather than by a failed sync.
Schedule::command('gbp:refresh-tokens')
    ->dailyAt('01:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer();
