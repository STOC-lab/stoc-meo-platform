# Configuration and environment

## Billing hangs off the organization

Cashier's customer model is `Organization`, not `User` — set in
`AppServiceProvider::boot()`, with the columns moved by the
`align_billing_with_cashier` migration and `subscriptions.organization_id` as
the foreign key. Cashier's own routes are ignored; the webhook route is declared
in `bootstrap/app.php` so it points at this application's controller.

## Timezone

`config/app.php` reads `env('APP_TIMEZONE', 'UTC')`, and the value in use is
`UTC`. See `queues-and-schedule.md` before changing it: existing timestamps were
written by a UTC clock, and the schedule already pins Tokyo per task.

## Which integrations are configured is a runtime question

Providers answer `isAvailable()` from whether their key is present, and the
application is expected to run with some of them absent. Never assume a key
exists; ask the provider or the factory. As of the v0.1.0-mvp tag the deployment
has Stripe (test mode) and Redis configured, and DataForSEO, Google/GBP,
Anthropic and Instagram unconfigured, with `MAIL_MAILER=log`.

## Keep .env.example in step

A key added to `config/` belongs in `.env.example` the same commit, with the
value the deployment actually uses when that value is load-bearing.

## Cached config

A deployed host runs `php artisan config:cache`, so an edit to a file in
`config/` has no effect there until the cache is rebuilt. Two consequences:

- Changing a `config/` default is not live on its own. Say so when handing the
  change over, and check whether `.env` needs to change with it.
- Do not clear the cache to work around something. Tests already avoid it; see
  `testing.md`.
