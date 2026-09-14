<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;

/**
 * Prints the switch from Stripe's test mode to live, filled in from this
 * database.
 *
 * The order is the one in `.ai/rules/stripe.md` and so is the reason for it: a
 * test `price_…` does not resolve in live mode, so the prices have to exist
 * and `plans.stripe_price_id` has to point at them before the keys change.
 * Between step 2 and step 4 the application cannot take a payment, which is
 * why this prints the whole switch at once rather than a step at a time.
 *
 * Nothing here talks to Stripe and nothing here writes to the database. It
 * reads the plan catalogue and the billing rows that are actually present and
 * prints the commands and the SQL for a person to run — the seven names and
 * amounts are the part that is easy to mistype and expensive to get wrong, and
 * they are not worth copying by hand.
 *
 * Run it again with the new price ids to have the UPDATE statements filled in:
 *
 *     php artisan stripe:go-live --price=meo_light=price_1AbC…
 *
 * Nothing is indented: every command and statement below is meant to be
 * selected and pasted as it stands.
 */
class PrepareStripeGoLive extends Command
{
    protected $signature = 'stripe:go-live
                            {--price=* : A live price id for a plan, given as code=price_id}';

    protected $description = 'Print the runbook, SQL and Stripe commands for moving billing to live mode';

    /**
     * The events `StripeWebhookController` answers, and so the events the live
     * endpoint has to be subscribed to.
     *
     * An event missing here arrives at an endpoint that ignores it; an event
     * here with no handler is a subscription paid for and never activated.
     * `StripeGoLiveTest` holds this list against the controller's methods so
     * the two cannot drift apart quietly.
     *
     * @var array<int, string>
     */
    public const WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'invoice.paid',
        'invoice.payment_failed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function handle(): int
    {
        $plans = Plan::orderBy('sort_order')->get();

        if ($plans->isEmpty()) {
            $this->error('The plan catalogue is empty. Seed it before going live.');

            return self::FAILURE;
        }

        $live = $this->livePriceIds($plans);

        $this->currentState($plans);
        $this->stepCreatePrices($plans);
        $this->stepRepointPlans($plans, $live);
        $this->stepClearTestBillingLinks();
        $this->stepSwapKeys();
        $this->stepWebhookEndpoint();
        $this->stepRebuildCaches();
        $this->stepVerify();

        return self::SUCCESS;
    }

    /**
     * The live price ids passed on the command line, keyed by plan code.
     *
     * @param  Collection<int, Plan>  $plans
     * @return array<string, string>
     */
    protected function livePriceIds(Collection $plans): array
    {
        $codes = $plans->pluck('code')->all();
        $ids = [];

        foreach ((array) $this->option('price') as $pair) {
            [$code, $priceId] = array_pad(explode('=', (string) $pair, 2), 2, null);
            $code = trim((string) $code);
            $priceId = trim((string) $priceId);

            if (! in_array($code, $codes, true)) {
                $this->warn("Ignoring --price={$pair}: there is no plan with the code [{$code}].");

                continue;
            }

            if (! str_starts_with($priceId, 'price_')) {
                $this->warn("Ignoring --price={$pair}: a price id starts with price_.");

                continue;
            }

            $ids[$code] = $priceId;
        }

        return $ids;
    }

    /**
     * @param  Collection<int, Plan>  $plans
     */
    protected function currentState(Collection $plans): void
    {
        $secret = (string) config('cashier.secret');

        $this->components->info('Where this deployment is now');

        $this->components->twoColumnDetail('Stripe secret key', match (true) {
            str_starts_with($secret, 'sk_live_') => '<fg=green>live</>',
            str_starts_with($secret, 'sk_test_') => '<fg=yellow>test</>',
            default => '<fg=red>not set</>',
        });

        $this->components->twoColumnDetail('Webhook signing secret', filled(config('cashier.webhook.secret'))
            ? 'set'
            : '<fg=red>not set — the endpoint would accept any POST</>');

        $this->components->twoColumnDetail(
            'Plans with a price id',
            $plans->whereNotNull('stripe_price_id')->count().' of '.$plans->count(),
        );

        $this->components->twoColumnDetail(
            'Organizations carrying a Stripe customer',
            (string) Organization::whereNotNull('stripe_id')->count(),
        );

        $this->components->twoColumnDetail(
            'Subscription rows',
            Subscription::count().' ('.SubscriptionItem::count().' items)',
        );

        $this->newLine();
    }

    /**
     * @param  Collection<int, Plan>  $plans
     */
    protected function stepCreatePrices(Collection $plans): void
    {
        $this->components->info('1. Create the products and prices in live mode');

        $this->comment('--live is on every command on purpose: the CLI defaults to test mode,');
        $this->comment('and a price created in the wrong mode looks right and resolves nowhere.');
        $this->comment('JPY is zero-decimal, so --unit-amount is the yen figure itself —');
        $this->comment('9000 is ¥9,000, not ¥90.');
        $this->newLine();

        $this->line('stripe login --live');
        $this->newLine();

        foreach ($plans as $plan) {
            $this->line("# {$plan->name} — ¥".number_format((int) $plan->price).'/月');
            $this->line('stripe products create --live \\');
            $this->line('  --name='.escapeshellarg((string) $plan->name).' \\');
            $this->line('  --description='.escapeshellarg((string) $plan->description).' \\');
            $this->line("  --metadata[plan_code]={$plan->code}");
            $this->newLine();
            $this->line('stripe prices create --live \\');
            $this->line('  --product=prod_REPLACE_WITH_THE_ID_ABOVE \\');
            $this->line('  --unit-amount='.(int) $plan->price.' \\');
            $this->line('  --currency='.strtolower((string) $plan->currency).' \\');
            $this->line('  --recurring[interval]='.$plan->interval.' \\');
            $this->line("  --lookup-key={$plan->code}");
            $this->newLine();
        }

        $this->comment('The lookup key on each price is worth the keystrokes: it is how you find');
        $this->comment('the right price again without reading ids, and it is unique per mode, so');
        $this->comment('a clash is Stripe telling you that you are in the wrong one.');
        $this->newLine();
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @param  array<string, string>  $live
     */
    protected function stepRepointPlans(Collection $plans, array $live): void
    {
        $this->components->info('2. Re-point plans.stripe_price_id at the live prices');

        $this->comment('Matched on name, never on id: the plan ids belong to this application,');
        $this->comment('and reusing them as a shortcut is how the wrong price ends up attached');
        $this->comment('to a plan.');
        $this->newLine();

        if ($live === []) {
            $this->comment('Run this command again with the ids from step 1 to have the statements');
            $this->comment('filled in for you:');
            $this->newLine();
            $this->line('php artisan stripe:go-live \\');

            $last = $plans->last();

            foreach ($plans as $plan) {
                $this->line("  --price={$plan->code}=price_…".($plan->is($last) ? '' : ' \\'));
            }

            $this->newLine();
        }

        $this->line('START TRANSACTION;');

        foreach ($plans as $plan) {
            $this->line(sprintf(
                "UPDATE plans SET stripe_price_id = '%s', updated_at = NOW() WHERE name = '%s';",
                $live[$plan->code] ?? 'price_REPLACE_'.strtoupper($plan->code),
                $plan->name,
            ));
        }

        $this->line('');
        $this->line('-- '.$plans->count().' rows, no more and no less, and nothing left on a test price.');
        $this->line('SELECT name, code, stripe_price_id FROM plans ORDER BY sort_order;');
        $this->line('COMMIT;');
        $this->newLine();

        $missing = $plans->reject(fn (Plan $plan) => isset($live[$plan->code]));

        if ($live !== [] && $missing->isNotEmpty()) {
            $this->warn('Still waiting on a live price id for: '.$missing->pluck('code')->implode(', '));
            $this->newLine();
        }

        $this->comment('PlanSeeder does not write stripe_price_id, so re-running the seeder');
        $this->comment('afterwards will not undo this.');
        $this->newLine();
    }

    protected function stepClearTestBillingLinks(): void
    {
        $this->components->info('3. Clear the test-mode billing links — before the keys change');

        $this->comment('A customer id minted in test mode means nothing in live. Cashier still');
        $this->comment('sends it, so the organization gets "No such customer" on its next action');
        $this->comment('rather than a live customer of its own.');
        $this->newLine();

        $organizations = Organization::whereNotNull('stripe_id')->get(['id', 'name', 'stripe_id']);

        if ($organizations->isEmpty()) {
            $this->comment('Nothing to clear: no organization carries a Stripe customer id.');
            $this->newLine();

            return;
        }

        foreach ($organizations as $organization) {
            $this->line("-- {$organization->name} (id {$organization->id}) currently {$organization->stripe_id}");
        }

        $this->line('');
        $this->line('START TRANSACTION;');
        $this->line('DELETE si FROM subscription_items si JOIN subscriptions s ON s.id = si.subscription_id;');
        $this->line('DELETE FROM subscriptions;');
        $this->line('UPDATE organizations SET stripe_id = NULL, updated_at = NOW() WHERE stripe_id IS NOT NULL;');
        $this->line('COMMIT;');
        $this->newLine();

        $this->comment('This throws away the test-mode history, which is the intent — it is test');
        $this->comment('data, and Stripe keeps its own copy either way.');
        $this->newLine();
    }

    protected function stepSwapKeys(): void
    {
        $this->components->info('4. Swap the keys in .env');

        $this->line('STRIPE_KEY=pk_live_…');
        $this->line('STRIPE_SECRET=sk_live_…');
        $this->newLine();

        $this->comment('STRIPE_WEBHOOK_SECRET comes from step 5 and is a different secret from');
        $this->comment('the test endpoint\'s. Carrying the old one over means every live webhook');
        $this->comment('is rejected as unsigned, silently, and no subscription ever activates.');
        $this->newLine();
    }

    protected function stepWebhookEndpoint(): void
    {
        $this->components->info('5. Create the live webhook endpoint');

        $this->line('stripe webhook_endpoints create --live \\');
        $this->line('  --url='.$this->webhookUrl().' \\');

        foreach (self::WEBHOOK_EVENTS as $event) {
            $this->line("  --enabled-events={$event} \\");
        }

        $this->line('  --description='.escapeshellarg('STOC MEO production'));
        $this->newLine();
        $this->line('STRIPE_WEBHOOK_SECRET=whsec_…   # the signing secret the command returns');
        $this->newLine();

        $this->comment('Exactly these '.count(self::WEBHOOK_EVENTS).' events: they are the ones StripeWebhookController');
        $this->comment('answers. customer.subscription.created is not optional — a real Checkout');
        $this->comment('fires it and then checkout.session.completed, and no .updated at all.');
        $this->newLine();
    }

    protected function stepRebuildCaches(): void
    {
        $this->components->info('6. Rebuild the config cache, then the workers');

        $this->line('php artisan config:cache');
        $this->line('php artisan horizon:terminate');
        $this->newLine();

        $this->comment('All six Stripe values are read through config(), and a deployed host');
        $this->comment('serves the cached copy until it is rebuilt. The Horizon workers hold');
        $this->comment('their own copy from when they booted, so anything queued that touches');
        $this->comment('billing keeps using the test keys until supervisor brings them back.');
        $this->newLine();
    }

    protected function stepVerify(): void
    {
        $this->components->info('Afterwards');

        $this->line('php artisan stripe:go-live   # the state above should now say live');
        $this->newLine();
        $this->line('stripe listen --live --forward-to '.$this->webhookUrl());
        $this->newLine();

        $this->comment('stripe listen proves the endpoint accepts a live signature. Only a real');
        $this->comment('card proves the rest: test cards do not work in live mode, so the whole');
        $this->comment('path is confirmed by one smallest-amount live subscription, cancelled');
        $this->comment('and refunded afterwards.');
        $this->newLine();
    }

    protected function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/stripe/webhook';
    }
}
