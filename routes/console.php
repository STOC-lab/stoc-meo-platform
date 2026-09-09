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
