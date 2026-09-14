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

As of 2026-09-14 the deployment has Stripe (test mode), Redis, DataForSEO
(`DATAFORSEO_SANDBOX=false`, so every keyword check is billed), Resend,
Google/GBP and Anthropic. Instagram is the only one left with no credentials.

Google/GBP has both halves: `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` with
`GOOGLE_REDIRECT_URI` pointing at `/api/v1/auth/google/callback`, and one
connected account in `gbp_accounts`. Being configured is not the same as being
healthy — the nightly performance sync has been failing on HTTP 429 from
`businessprofileperformance.googleapis.com`, which is a Google-side per-minute
quota on the project, not a credential problem. Read the exception before
blaming the connection.

`ANTHROPIC_API_KEY` is set, and the whole path is known to work end to end:
`ai:insights daily` queued a `GenerateDailyAnalysisJob`, Horizon ran it off the
`ai` queue, and the answer landed in `analyses` with `model` recorded as the
dated id the API returned. The AI results live in `analyses` and
`improvement_proposals`; there is no `ai_runs` table.

## Keep .env.example in step

A key added to `config/` belongs in `.env.example` the same commit, with the
value the deployment actually uses when that value is load-bearing.

## Mail goes through Resend's API

`MAIL_MAILER=resend` and `RESEND_API_KEY`, not SMTP. The from address is still
`info@stoc-plus.site`, the XServer mailbox that owns the domain, so the change
is invisible in a received message — what changed is that nothing dials a mail
host any more. `MAIL_HOST`, `MAIL_PORT`, `MAIL_PASSWORD` and `MAIL_ENCRYPTION`
are gone from `.env`; a leftover `MAIL_USERNAME` is read by nobody. Do not
reintroduce SMTP settings to "fix" mail — the Resend transport ignores them.

## Laravel 11 renamed two keys, and kept no alias

Both are now correct in `.env`, but the old names are still what a search
turns up, so they are worth knowing:

- `CACHE_DRIVER` → `CACHE_STORE`. This one bit: `.env` set `redis` under the
  old name while `config('cache.default')` answered `database`, and queue and
  session being on Redis is what made it look configured. Fixed — cache,
  queue and session are all Redis now.
- `MAIL_ENCRYPTION` → `MAIL_SCHEME`. Moot since mail moved to Resend.

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
