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
 * - The product id is derived from the plan code (`stoc_meo_premium_1y`), so
 *   a second run retrieves the product it made the first time rather than
 *   making another one with the same name. Stripe lets a product id be chosen;
 *   searching by name would not, because names are not unique and the search
 *   index lags a minute behind a create.
 *
 *   One product per plan, and not one shared product with nine prices on it,
 *   because the name Checkout shows the customer is the *product's*. A price
 *   cannot override it — `price_data.product_data` is for an ad-hoc price and
 *   is refused beside an existing one, and `nickname` is hidden from
 *   customers. Share the product and every term reads as one unnamed service
 *   on the payment page, the invoice and the customer portal alike. This was
 *   tried on 2026-09-17 and undone the same day.
 * - The price carries the plan code as its lookup key, which *is* unique per
 *   account, so a second run finds the price rather than minting a rival.
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
     * Every product this command makes is named from the plan code, so the
     * dashboard reads as the catalogue does and a re-run is a retrieve.
     */
    public const PRODUCT_ID_PREFIX = 'stoc_';

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
        $productId = self::PRODUCT_ID_PREFIX.$plan->code;

        if ($this->option('dry-run')) {
            $this->line(sprintf(
                '  %s — %s ¥%s %s',
                $plan->code,
                $productId,
                number_format((int) $plan->price),
                $plan->isRecurring() ? "every 1 {$plan->interval}" : 'once',
            ));

            return [$plan->code, $productId, '—', 'DRY RUN'];
        }

        $product = $this->product($productId, $plan);
        $existing = $this->existingPrice($plan);

        if ($existing !== null && ! $this->priceMatches($existing, $plan)) {
            return [$plan->code, $product->id, $existing->id, 'MISMATCH'];
        }

        $price = $existing ?? $this->createPrice($productId, $plan);

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
     * The plan's product, retrieved if this command has made it before.
     */
    protected function product(string $productId, Plan $plan): Product
    {
        try {
            return $this->stripe()->products->retrieve($productId);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
        }

        return $this->stripe()->products->create([
            'id' => $productId,
            'name' => $plan->name,
            'description' => $plan->description,
            'metadata' => $this->metadata($plan),
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
