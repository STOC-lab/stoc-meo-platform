<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Creates a Stripe product and price for every active paid plan, and writes
 * the price id back onto the plan row.
 *
 * This is the step `stripe:go-live` prints by hand, done by the application
 * instead. Everything it does is idempotent, so a half-finished run is fixed
 * by running it again:
 *
 * - Every plan is a price on one product, `stoc_meo_service`. The catalogue is
 *   one service sold on different terms, not nine services, so the terms are
 *   what a price says and the product says what is being bought. The id is
 *   fixed rather than searched for: Stripe lets a product id be chosen, while
 *   product search lags about a minute behind a create, so a re-run inside
 *   that minute would make a second one.
 * - The price carries the plan code as its lookup key, which is unique per
 *   account, so a second run finds the price rather than minting a rival, and
 *   its nickname and metadata are what name the term in the dashboard.
 *
 * A price in Stripe is immutable. If one already exists under a plan's lookup
 * key for a different amount, that is the catalogue having moved under a price
 * customers may be paying on, and this command says so and writes nothing
 * rather than picking one of the two.
 *
 * Which Stripe account it talks to is `STRIPE_SECRET`, and a price created in
 * the wrong mode looks perfectly right and resolves nowhere, so `--mode` is
 * required and is checked against the key before anything is sent.
 */
class CreateStripeProducts extends Command
{
    protected $signature = 'stripe:create-products
                            {--mode= : The account this run is meant for, test or live. Checked against STRIPE_SECRET.}
                            {--only=* : Limit the run to these plan codes}
                            {--dry-run : Print what would be sent and write nothing, to Stripe or the database}';

    protected $description = 'Create the Stripe product and price for every active paid plan and record the price ids';

    /**
     * The one product every plan's price hangs off, with an id this command
     * chooses so that a second run retrieves it rather than making a rival.
     */
    public const PRODUCT_ID = 'stoc_meo_service';

    public const PRODUCT_NAME = 'STOC MEO Service';

    public const PRODUCT_DESCRIPTION = 'STOC MEO / IG LINE の契約プラン。契約期間ごとの料金は価格側が持つ。';

    /**
     * Retrieved or created once per run, not once per plan.
     */
    protected ?Product $product = null;

    public function handle(): int
    {
        $mode = $this->resolveMode();

        if ($mode === null) {
            return self::FAILURE;
        }

        $plans = $this->plans();

        if ($plans->isEmpty()) {
            $this->components->error('No active paid plan needs a Stripe price. Seed the catalogue first.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s %d plan%s against the %s account.',
            $this->option('dry-run') ? 'Would create' : 'Creating',
            $plans->count(),
            $plans->count() === 1 ? '' : 's',
            $mode,
        ));

        $rows = [];
        $failed = false;

        foreach ($plans as $plan) {
            $row = $this->syncPlan($plan);
            $rows[] = $row;
            $failed = $failed || $row[3] === 'MISMATCH';
        }

        $this->newLine();
        $this->table(['Plan', 'Product', 'Price', 'Result'], $rows);

        if ($failed) {
            $this->newLine();
            $this->components->error('A price already exists for a different amount. Nothing was written for it — a price cannot be edited in Stripe, so changing one means retiring it and minting another, and that is a decision for a person.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->warn('Dry run: nothing was sent to Stripe and no plan was written.');
        }

        return self::SUCCESS;
    }

    /**
     * The mode this run is for, or null when it does not match the key.
     */
    protected function resolveMode(): ?string
    {
        $requested = (string) $this->option('mode');
        $secret = (string) config('cashier.secret');

        $configured = match (true) {
            str_starts_with($secret, 'sk_live_') => 'live',
            str_starts_with($secret, 'sk_test_') => 'test',
            default => null,
        };

        if ($configured === null) {
            $this->components->error('STRIPE_SECRET is not a Stripe secret key, so there is no account to create anything in.');

            return null;
        }

        if (! in_array($requested, ['test', 'live'], true)) {
            $this->components->error('--mode=test or --mode=live is required.');
            $this->components->bulletList([
                "STRIPE_SECRET is a {$configured} key.",
                'A price created in the wrong mode is not an error Stripe reports: it is created, it looks right, and it resolves nowhere when the other mode is in use.',
                "Run: php artisan stripe:create-products --mode={$configured}",
            ]);

            return null;
        }

        if ($requested !== $configured) {
            $this->components->error("--mode={$requested} was asked for, but STRIPE_SECRET is a {$configured} key. Swap the key or the flag; nothing has been sent.");

            return null;
        }

        return $configured;
    }

    /**
     * The plans that need a Stripe price: active, and charged for.
     *
     * A free plan has nothing to bill, and `BillingController` reads the empty
     * `stripe_price_id` as exactly that. A retired plan is one nobody may buy
     * any more, so a live price for it would be a price with no way to reach
     * it.
     *
     * @return Collection<int, Plan>
     */
    protected function plans(): Collection
    {
        $only = array_filter((array) $this->option('only'));

        return Plan::active()
            ->where('price', '>', 0)
            ->when($only !== [], fn ($query) => $query->whereIn('code', $only))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    protected function syncPlan(Plan $plan): array
    {
        if ($this->option('dry-run')) {
            $this->line(sprintf(
                '  %s — %s ¥%s %s',
                $plan->code,
                self::PRODUCT_ID,
                number_format((int) $plan->price),
                $plan->isRecurring() ? "every 1 {$plan->interval}" : 'once',
            ));

            return [$plan->code, self::PRODUCT_ID, '—', 'DRY RUN'];
        }

        $product = $this->product();
        $existing = $this->existingPrice($plan);

        if ($existing !== null && ! $this->priceMatches($existing, $plan)) {
            return [$plan->code, $product->id, $existing->id, 'MISMATCH'];
        }

        $price = $existing ?? $this->createPrice($product->id, $plan);

        $replaced = filled($plan->stripe_price_id) && $plan->stripe_price_id !== $price->id;

        $plan->forceFill(['stripe_price_id' => $price->id])->save();

        return [
            $plan->code,
            $product->id,
            $price->id,
            match (true) {
                $existing !== null => 'reused',
                $replaced => 'created, replacing '.$plan->getOriginal('stripe_price_id'),
                default => 'created',
            },
        ];
    }

    /**
     * The one product the whole catalogue hangs off, retrieved if this command
     * has made it before and made once per run either way.
     */
    protected function product(): Product
    {
        if ($this->product !== null) {
            return $this->product;
        }

        try {
            return $this->product = $this->stripe()->products->retrieve(self::PRODUCT_ID);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
        }

        return $this->product = $this->stripe()->products->create([
            'id' => self::PRODUCT_ID,
            'name' => self::PRODUCT_NAME,
            'description' => self::PRODUCT_DESCRIPTION,
        ]);
    }

    /**
     * The price already carrying this plan's lookup key, if there is one.
     *
     * Lookup keys are unique within an account, which is what makes this a
     * safe idempotency check and not a guess — and a clash across modes is
     * Stripe saying you are in the wrong one.
     */
    protected function existingPrice(Plan $plan): ?Price
    {
        $prices = $this->stripe()->prices->all([
            'lookup_keys' => [$plan->code],
            'limit' => 1,
        ]);

        return $prices->data[0] ?? null;
    }

    protected function createPrice(string $productId, Plan $plan): Price
    {
        $payload = [
            'product' => $productId,
            // JPY is zero-decimal: unit_amount is the yen figure itself, so
            // 384000 is ¥384,000 and not ¥3,840.
            'unit_amount' => (int) $plan->price,
            'currency' => strtolower((string) $plan->currency),
            'lookup_key' => $plan->code,
            'nickname' => $plan->name,
            'metadata' => $this->metadata($plan),
        ];

        if ($plan->isRecurring()) {
            $payload['recurring'] = [
                'interval' => $plan->interval,
                'interval_count' => 1,
            ];
        }

        return $this->stripe()->prices->create($payload);
    }

    protected function priceMatches(Price $price, Plan $plan): bool
    {
        $recurring = $price->recurring;

        return $price->unit_amount === (int) $plan->price
            && $price->currency === strtolower((string) $plan->currency)
            && ($plan->isRecurring()
                ? $recurring?->interval === $plan->interval && $recurring?->interval_count === 1
                : $recurring === null);
    }

    /**
     * What the plan is, written onto the Stripe objects so the dashboard and
     * the webhook payloads can be read without this database open beside them.
     *
     * @return array<string, string>
     */
    protected function metadata(Plan $plan): array
    {
        return [
            'plan_code' => $plan->code,
            'billing_period_months' => (string) $plan->billing_period_months,
            'phases' => (string) $plan->phases,
        ];
    }

    protected function stripe(): StripeClient
    {
        return Cashier::stripe();
    }
}
