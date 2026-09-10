# Jobs and the schedule

## Queues are named per job

A job declares its queue as a `const QUEUE` and sets it in the constructor —
`rankings`, `heatmap`, `reports`, `gbp`, `ai`, `social`, or the default. The
Horizon supervisors in `config/horizon.php` are sized per queue, so a job put on
the wrong one gets the wrong timeout and the wrong concurrency. Outbound HTTP
work does not belong on `default`.

## The clock is Tokyo, and every task says so anyway

The deployment runs on `APP_TIMEZONE=Asia/Tokyo`, and so do the host and MySQL.
`config-and-env.md` has the reasoning and the one thing to watch: rows written
before 2026-09-09 18:18 JST are UTC, because the application ran on UTC until
then.

That makes the `->timezone('Asia/Tokyo')` on each task redundant today. Keep it
anyway. It is the difference between a task that runs at 02:00 JST and one that
runs at 02:00 in whatever zone the host happens to be in, and it is what stops
a future `APP_TIMEZONE` change from silently moving every overnight job:

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
