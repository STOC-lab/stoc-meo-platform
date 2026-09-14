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

`RunAiInsights` asks `AIProviderFactory::isAvailable()` once, before the chunk
loop, and skips only the jobs that call a model — the MEO scores still run,
because they need no provider and are what everything else is written from.
Without that check an unconfigured deployment dispatched analysis jobs every
night that threw on the first line and landed in `failed_jobs`.

Copy that shape when adding a command that fans out to a provider: ask the
factory (`AIProviderFactory::isAvailable()`,
`RankProviderInterface::isAvailable()`) first, say so on the console, and queue
the part that does not need it rather than exiting whole.

## A Business Profile refusal is retried only when waiting would help

`GBPException` carries `isTransient()`, set by `GBPClient::isTransientStatus()`:
429 and 5xx and a connection that never opened, and nothing else. Google meters
the Business Profile APIs per minute and per day, so a 429 says nothing about
the store front and the same call is answered later. A 403 is not transient on
purpose — "the API has not been used in project N before or it is disabled" is
Google's settled answer, and no amount of waiting enables an API.

`SyncGBPPerformanceJob` and `SyncReviewsJob` use
`RetriesTransientGbpFailures`, which spends the attempts on a transient failure
and then lets the job end quietly. That is only safe because both sweeps ask
for a window and overwrite what they hold — a night lost to a rate limit is
made good by the next one, so a `failed_jobs` row nobody can act on would only
bury the ones they can. A permanent refusal is re-raised every time and does
reach `failed_jobs`, which is the point.

Both carry `tries = 4`, not 3, so the last of `[60, 300, 900]` is actually
waited out. A three-try job never reaches the third delay: check the length of
the backoff list against `tries` before believing the delays it lists.

Do not extend this to `PublishGbpPostJob` or `PublishReviewReplyJob`. Those
carry one piece of a person's content, nothing re-runs them, and each already
closes off the post through `failed()` so the failure is visible in the
product.
