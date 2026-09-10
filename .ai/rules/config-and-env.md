# Configuration and environment

## Billing hangs off the organization

Cashier's customer model is `Organization`, not `User` — set in
`AppServiceProvider::boot()`, with the columns moved by the
`align_billing_with_cashier` migration and `subscriptions.organization_id` as
the foreign key. Cashier's own routes are ignored; the webhook route is declared
in `bootstrap/app.php` so it points at this application's controller.

### Checkout metadata goes to two different places

Cashier's `withMetadata()` writes `subscription_data.metadata`, which reaches
the `customer.subscription.*` events and nothing else. The Checkout session's
own `metadata` — what `checkout.session.completed` carries — is only set by a
session option passed to `checkout()`. `BillingService::checkoutUrl()` sends
both, and it has to: with only the first, `session.metadata` arrives empty,
the webhook falls back to the Stripe customer for the organization and cannot
tell which plan was bought, so `plan_id` silently keeps its old value.

Do not assume a following `customer.subscription.updated` will repair it. A
real test-mode Checkout fired `customer.subscription.created` and then
`checkout.session.completed`, and no `updated` at all. Anything that has to be
true after a purchase belongs on `created` as well; both go through
`mirrorOntoOrganization()`, which reads the plan from the price rather than
from metadata.

The endpoint must therefore be subscribed to `customer.subscription.created`.
It was not, until 2026-09-10.

## Timezone

`config/app.php` reads `env('APP_TIMEZONE', 'UTC')`, and the deployment sets
`Asia/Tokyo`. That is the settled value, not an accident: the product is sold
in Japan, so the date a shop owner reads is the date they mean, and the host
and MySQL are on JST too — `@@time_zone` is `SYSTEM`, so `NOW()` and `now()`
agree without either side converting.

Do not move it back to UTC to match a framework default. Every row written
since the change is JST, and reinterpreting them is the same mistake in the
other direction.

### The seam at 2026-09-09 18:18 JST

The application did run on UTC before that. `.env` was edited at
`2026-09-09 18:18:40 +0900` and the config cache was rebuilt in the same
second, so rows written before that instant carry UTC timestamps and rows after
carry JST. Nothing converted the older ones: it was nine hours across a few
days of pre-launch data, and correcting it was judged worse than recording it.

The consequence is narrow but real. A query whose window crosses that instant
is comparing two clocks, and a nine-hour gap in the earliest history is that,
not missing data. Say so rather than silently compensating, and do not use the
oldest rows as evidence about anything that depends on the hour.

### Tests do not inherit it

`phpunit.xml` pins `APP_TIMEZONE` itself, deliberately, so the suite does not
move with the host. See `testing.md` before changing what it pins.

## Which integrations are configured is a runtime question

Providers answer `isAvailable()` from whether their key is present, and the
application is expected to run with some of them absent. Never assume a key
exists; ask the provider or the factory.

As of 2026-09-10 the deployment has Stripe (test mode), Redis, DataForSEO
(`DATAFORSEO_SANDBOX=false`, so every keyword check is billed) and SMTP through
the XServer mailbox that owns the from address. Google/GBP, Anthropic and
Instagram have no credentials.

## Keep .env.example in step

A key added to `config/` belongs in `.env.example` the same commit, with the
value the deployment actually uses when that value is load-bearing.

## Two keys in `.env` that nothing reads

Laravel 11 renamed both of these and kept no alias, so the old name sits in
`.env` looking effective while the application runs on the default:

- `CACHE_DRIVER` → `CACHE_STORE`. This one bites: `.env` says `redis` under the
  old name, and `config('cache.default')` answers `database`. Queue and session
  *are* on Redis, which is what makes it look configured.
- `MAIL_ENCRYPTION` → `MAIL_SCHEME`. Harmless only by luck — `MailManager`
  falls back to `smtps` whenever the port is 465, which is the port in use.

When a `.env` value seems not to take effect, check the key still exists in
`config/` before looking anywhere else. `.env.example` carries the correct
names.

## Cached config

A deployed host runs `php artisan config:cache`, so an edit to a file in
`config/` has no effect there until the cache is rebuilt. Two consequences:

- Changing a `config/` default is not live on its own. Say so when handing the
  change over, and check whether `.env` needs to change with it.
- Do not clear the cache to work around something. Tests already avoid it; see
  `testing.md`.
