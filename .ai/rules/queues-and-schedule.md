# Jobs and the schedule

## Queues are named per job

A job declares its queue as a `const QUEUE` and sets it in the constructor —
`rankings`, `heatmap`, `reports`, `gbp`, `ai`, `social`, or the default. The
Horizon supervisors in `config/horizon.php` are sized per queue, so a job put on
the wrong one gets the wrong timeout and the wrong concurrency. Outbound HTTP
work does not belong on `default`.

## The application clock is UTC; the schedule is Tokyo

`config/app.php` resolves `APP_TIMEZONE` but the deployment sets it to `UTC`,
and every timestamp in the database was written by a UTC clock. Do not move the
application to `Asia/Tokyo` — that reinterprets existing rows rather than
converting them.

Anything that must happen at a particular local hour says so on the task:

    Schedule::command('rankings:fetch-daily')
        ->dailyAt('02:00')
        ->timezone('Asia/Tokyo')
        ->withoutOverlapping()
        ->onOneServer();

Keep `->withoutOverlapping()` and `->onOneServer()` on every scheduled command.

## The scheduler is a process, not a cron entry

`php artisan schedule:work` and `php artisan horizon` both run under supervisor
(`/etc/supervisor/conf.d/stoc-meo.conf`). There is no `schedule:run` line in any
crontab, and adding one would double every task.

## A command whose provider is unconfigured should say so

`RunAiInsights` has no availability check, so with no `ANTHROPIC_API_KEY` the
nightly run dispatches jobs that throw and land in `failed_jobs`. When adding a
command that fans out to a provider, ask the factory
(`AIProviderFactory::isAvailable()`, `RankProviderInterface::isAvailable()`)
first and exit cleanly.
