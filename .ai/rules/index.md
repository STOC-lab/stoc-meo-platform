# Project rules

Settled decisions, non-obvious traps and standing constraints for this
application. Read every file whose globs cover the paths you are about to
touch, before you write code.

| Rules | Applies to |
| --- | --- |
| [testing.md](testing.md) | `tests/**`, `phpunit.xml`, `scripts/**` |
| [models.md](models.md) | `app/Models/**`, `database/migrations/**`, `database/factories/**` |
| [api.md](api.md) | `app/Http/**`, `routes/api.php`, `resources/js/**` |
| [queues-and-schedule.md](queues-and-schedule.md) | `app/Jobs/**`, `app/Console/Commands/**`, `routes/console.php` |
| [ranking.md](ranking.md) | `app/Services/Ranking/**`, `app/Jobs/FetchDailyRankingsJob.php`, `app/Jobs/FetchHeatmapJob.php` |
| [config-and-env.md](config-and-env.md) | `config/**`, `.env.example`, `bootstrap/**` |
| [stripe.md](stripe.md) | `app/Http/Controllers/StripeWebhookController.php`, `app/Services/BillingService.php`, `app/Http/Controllers/Api/V1/BillingController.php`, `config/cashier.php` |

A path match alone will miss things. Also run `grep -rin '<keyword>' .ai/rules`
for whatever you are about to change.
