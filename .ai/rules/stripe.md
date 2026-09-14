# Stripe

## The keys are test keys, and the price ids in the database match them

`.env` carries `sk_test_…` / `pk_test_…`, and all seven rows in `plans` hold
`stripe_price_id` values minted under that test account. **A test price id does
not resolve in live mode.** Swapping only the keys leaves every plan pointing at
a price Stripe will answer `No such price` for, and `BillingService::subscribe()`
fails on the first checkout anyone attempts.

So the keys are not the first step of going live. The prices are.

## The order of the switch

`php artisan stripe:go-live` prints this whole section filled in from the
database — the seven `stripe prices create` commands with the real names and
amounts, the `UPDATE` statements, the organizations still carrying a test
customer id, and the webhook command with the six events. Re-run it with
`--price=<code>=price_…` to have the UPDATEs filled in. It reads and prints
only; nothing in it writes anywhere. Use it rather than retyping the figures,
and keep the list below as the reasoning behind each step.

Stripe's live mode is a separate world: nothing created in test — prices,
customers, subscriptions, webhook endpoints — exists in it. Do these in order,
and do them in one sitting; between steps 2 and 4 the application cannot take a
payment.

1. **In the live dashboard, create the seven products and prices.** Currency
   JPY, recurring monthly, matching the test-mode ones. Collect the new
   `price_…` ids against plan names: MEO FREE / LIGHT / STANDARD / PREMIUM and
   IG LIGHT / STANDARD / PREMIUM.
2. **Re-point `plans.stripe_price_id`** at the live ids. Match on `name`, never
   on `id` — the plan ids are this application's, and reusing them as a
   shortcut is how the wrong price gets attached to a plan:

       Plan::where('name', 'MEO LIGHT')->update(['stripe_price_id' => 'price_…']);

3. **Clear the test-mode billing links.** One organization carries a test
   `stripe_id`, with one subscription and one subscription item behind it.
   Those customer and subscription ids are meaningless in live mode, and
   Cashier will try to use them: an organization with a stale `stripe_id`
   never gets a live customer created, it gets a `No such customer` on its next
   action. Null the `stripe_id` and delete the subscription rows before the
   keys change.
4. **Swap `STRIPE_KEY` and `STRIPE_SECRET`** for the live pair.
5. **Create the live webhook endpoint** at
   `https://meo.stoc-plus.site/stripe/webhook` and subscribe it to exactly the
   events the controller handles — `checkout.session.completed`,
   `invoice.paid`, `invoice.payment_failed`, `customer.subscription.created`,
   `customer.subscription.updated`, `customer.subscription.deleted`. Put its
   signing secret in `STRIPE_WEBHOOK_SECRET`. It is a different secret from the
   test endpoint's; carrying the old one over means every live webhook is
   rejected as unsigned, silently, and subscriptions never activate.
6. **`php artisan config:cache`.** All six values are read through `config()`,
   and a deployed host serves the cached copy until it is rebuilt.

## Things that stay true either way

The billable is `Organization`, not `User`. Cashier's own routes are turned off
in `AppServiceProvider` (`Cashier::ignoreRoutes()`) and both surviving routes
are declared in `bootstrap/app.php`, so a Cashier upgrade that adds routes adds
nothing here — check `bootstrap/app.php` when a Cashier route is missing.

`STRIPE_WEBHOOK_SECRET` is not optional. Without it Cashier skips
`VerifyWebhookSignature` entirely and the endpoint accepts any POST from
anyone, which on a public host is an open door to arbitrary subscription state.

The plan is read from the Checkout session's own metadata, and from the price
on the subscription as the fallback. Both paths have to survive a price id
change, which is the other reason step 2 comes before step 4.

## Verifying without waiting for a real customer

`stripe listen --forward-to https://meo.stoc-plus.site/stripe/webhook` against
the live endpoint confirms the signature is accepted. A ¥0 or smallest-amount
live subscription on a real card, then cancelled and refunded, is the only
thing that proves the whole path — test cards do not work in live mode.

## Four things a go-live plan keeps getting wrong

Each of these has been written into a task specification at least once. They
are all checkable in a minute and each one silently breaks live billing.

- **The endpoint is `/stripe/webhook`, not `/api/stripe/webhook`.** It is
  declared in `bootstrap/app.php`, outside the API prefix, because Cashier's
  own routes are ignored. Registering the `/api` form means every live delivery
  404s and no subscription ever activates. `php artisan route:list --path=webhook`
  settles it.
- **`PlanSeeder` does not carry `stripe_price_id`.** The column is never
  written by the seeder — that is deliberate, and it is what makes a reseed
  safe after the prices are re-pointed. Editing the seeder and running
  `db:seed` changes nothing. The ids live in the `plans` table and are changed
  by `UPDATE ... WHERE name = ...`; `php artisan stripe:go-live` prints the
  statements.
- **The event is `invoice.paid`, not `invoice.payment_succeeded`.** Cashier's
  parent handles the latter, so subscribing to it looks like it works — the
  subscriptions table keeps up, and the organization-level status and `plan_id`
  quietly do not.
- **Price ids have no mode in them.** There is no `price_live_` prefix; both
  modes mint `price_…`. The mode lives in the account the key belongs to, which
  is why a test id silently resolves to nothing rather than to an error you can
  read.

## Live keys must never be reachable from the suite

`phpunit.xml` pins `STRIPE_KEY`, `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`
with `force="true"`. There is no `.env.testing`; without the pin the suite
reads `.env`, and the Stripe SDK calls Guzzle directly rather than Laravel's
HTTP client, so `Http::fake()` and `preventStrayRequests()` do not stop it. On
live keys a stray call is a real charge. Do not remove the pin.

## Every verified delivery is logged

`StripeWebhookController::handleWebhook()` writes one `Stripe webhook received.`
line per delivery with the type, the event id, `livemode`, and whether anything
handles it. Stripe's dashboard shows that a delivery was made; only this says
what this side did with it, and "not arriving" and "arriving and ignored" are
otherwise the same picture.
