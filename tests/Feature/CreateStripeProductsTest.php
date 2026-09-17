<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * `stripe:create-products` is the one command in this application that makes
 * objects in a Stripe account, and the account it reaches is whichever
 * STRIPE_SECRET names. Two things are worth holding it to: that it cannot be
 * the thing that creates a live price against a test key, and that running it
 * twice does not mint a second price for a plan customers are already on.
 */
class CreateStripeProductsTest extends TestCase
{
    use RefreshDatabase;

    protected FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /**
     * The three-year MEO PREMIUM term: ¥300,000 billed once a year, three
     * times over.
     */
    protected function yearlyPlan(): Plan
    {
        return Plan::factory()->term(3)->create([
            'code' => 'meo_premium_3y',
            'name' => 'MEO PREMIUM 3年',
            'description' => 'MEO PREMIUM 3年契約。',
            'price' => 300000,
            'currency' => 'JPY',
            'stripe_price_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function stripePrice(array $overrides = []): array
    {
        return array_merge([
            'id' => 'price_1Live003',
            'object' => 'price',
            'unit_amount' => 300000,
            'currency' => 'jpy',
            'lookup_key' => 'meo_premium_3y',
            'recurring' => ['interval' => 'year', 'interval_count' => 1],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    protected function priceList(array $data): array
    {
        return ['object' => 'list', 'url' => '/v1/prices', 'has_more' => false, 'data' => $data];
    }

    public function test_a_plan_with_no_stripe_price_gets_a_product_and_a_recurring_price(): void
    {
        $plan = $this->yearlyPlan();

        $this->stripe->push('POST', '/v1/products', ['id' => 'stoc_meo_premium_3y', 'object' => 'product']);
        $this->stripe->push('GET', '/v1/prices', $this->priceList([]));
        $this->stripe->push('POST', '/v1/prices', $this->stripePrice());

        $this->artisan('stripe:create-products', ['--mode' => 'test'])->assertSuccessful();

        $this->assertSame('price_1Live003', $plan->fresh()->stripe_price_id);

        $product = $this->stripe->paramsFor('post', '/v1/products');
        $this->assertSame('stoc_meo_premium_3y', $product['id']);
        $this->assertSame('MEO PREMIUM 3年', $product['name']);
        $this->assertSame(
            ['plan_code' => 'meo_premium_3y', 'billing_period_months' => '36', 'phases' => '3'],
            $product['metadata'],
        );

        $price = $this->stripe->paramsFor('post', '/v1/prices');
        $this->assertSame('stoc_meo_premium_3y', $price['product']);
        // JPY is zero-decimal: 300000 is ¥300,000, not ¥3,000.
        $this->assertSame(300000, $price['unit_amount']);
        $this->assertSame('jpy', $price['currency']);
        $this->assertSame('meo_premium_3y', $price['lookup_key']);
        $this->assertSame(['interval' => 'year', 'interval_count' => 1], $price['recurring']);
    }

    public function test_a_one_time_plan_gets_a_price_with_no_recurring_block(): void
    {
        Plan::factory()->oneTime(6)->create([
            'code' => 'meo_premium_6m',
            'name' => 'MEO PREMIUM 6ヶ月特例',
            'price' => 210000,
            'currency' => 'JPY',
            'stripe_price_id' => null,
        ]);

        $this->stripe->push('POST', '/v1/products', ['id' => 'stoc_meo_premium_6m', 'object' => 'product']);
        $this->stripe->push('GET', '/v1/prices', $this->priceList([]));
        $this->stripe->push('POST', '/v1/prices', $this->stripePrice([
            'id' => 'price_1Live005',
            'unit_amount' => 210000,
            'lookup_key' => 'meo_premium_6m',
            'recurring' => null,
        ]));

        $this->artisan('stripe:create-products', ['--mode' => 'test'])->assertSuccessful();

        $price = $this->stripe->paramsFor('post', '/v1/prices');
        // A recurring block of any shape would make Stripe renew a term that
        // was sold as a single charge.
        $this->assertArrayNotHasKey('recurring', $price);
        $this->assertSame(210000, $price['unit_amount']);
    }

    public function test_a_second_run_reuses_the_product_and_price_it_made_the_first_time(): void
    {
        $plan = $this->yearlyPlan();
        $plan->forceFill(['stripe_price_id' => 'price_1Live003'])->save();

        $this->stripe->push('GET', '/v1/products/stoc_meo_premium_3y', ['id' => 'stoc_meo_premium_3y', 'object' => 'product']);
        $this->stripe->push('GET', '/v1/prices', $this->priceList([$this->stripePrice()]));

        $this->artisan('stripe:create-products', ['--mode' => 'test'])
            ->expectsOutputToContain('reused')
            ->assertSuccessful();

        $this->assertSame(0, $this->stripe->countFor('post', '/v1/prices'));
        $this->assertSame(0, $this->stripe->countFor('post', '/v1/products'));
        $this->assertSame('price_1Live003', $plan->fresh()->stripe_price_id);
    }

    public function test_a_price_that_exists_for_a_different_amount_fails_rather_than_being_adopted(): void
    {
        $plan = $this->yearlyPlan();

        $this->stripe->push('POST', '/v1/products', ['id' => 'stoc_meo_premium_3y', 'object' => 'product']);
        $this->stripe->push('GET', '/v1/prices', $this->priceList([$this->stripePrice(['unit_amount' => 324000])]));

        $this->artisan('stripe:create-products', ['--mode' => 'test'])
            ->expectsOutputToContain('MISMATCH')
            ->assertFailed();

        $this->assertSame(0, $this->stripe->countFor('post', '/v1/prices'));
        $this->assertNull($plan->fresh()->stripe_price_id);
    }

    public function test_asking_for_live_against_a_test_key_sends_nothing(): void
    {
        $plan = $this->yearlyPlan();

        $this->artisan('stripe:create-products', ['--mode' => 'live'])
            ->expectsOutputToContain('STRIPE_SECRET is a test key')
            ->assertFailed();

        $this->assertSame([], $this->stripe->requests);
        $this->assertNull($plan->fresh()->stripe_price_id);
    }

    public function test_omitting_the_mode_sends_nothing_and_names_the_key_in_use(): void
    {
        $this->yearlyPlan();

        $this->artisan('stripe:create-products')
            ->expectsOutputToContain('--mode=test or --mode=live is required')
            ->expectsOutputToContain('STRIPE_SECRET is a test key')
            ->assertFailed();

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_free_and_retired_plans_are_left_without_a_stripe_price(): void
    {
        $free = Plan::factory()->create(['code' => 'meo_free', 'price' => 0, 'stripe_price_id' => null]);
        $retired = Plan::factory()->create(['code' => 'meo_light', 'price' => 9000, 'is_active' => false, 'stripe_price_id' => null]);
        $this->yearlyPlan();

        $this->stripe->push('POST', '/v1/products', ['id' => 'stoc_meo_premium_3y', 'object' => 'product']);
        $this->stripe->push('GET', '/v1/prices', $this->priceList([]));
        $this->stripe->push('POST', '/v1/prices', $this->stripePrice());

        $this->artisan('stripe:create-products', ['--mode' => 'test'])->assertSuccessful();

        $this->assertSame(1, $this->stripe->countFor('post', '/v1/products'));
        $this->assertNull($free->fresh()->stripe_price_id);
        $this->assertNull($retired->fresh()->stripe_price_id);
    }

    public function test_a_dry_run_sends_nothing_and_writes_nothing(): void
    {
        $plan = $this->yearlyPlan();

        $this->artisan('stripe:create-products', ['--mode' => 'test', '--dry-run' => true])
            ->expectsOutputToContain('stoc_meo_premium_3y')
            ->expectsOutputToContain('nothing was sent to Stripe')
            ->assertSuccessful();

        $this->assertSame([], $this->stripe->requests);
        $this->assertNull($plan->fresh()->stripe_price_id);
    }

    public function test_only_limits_the_run_to_the_plans_named(): void
    {
        $this->yearlyPlan();
        $other = Plan::factory()->term(1)->create(['code' => 'ig_line_1y', 'price' => 180000, 'stripe_price_id' => null]);

        $this->stripe->push('POST', '/v1/products', ['id' => 'stoc_meo_premium_3y', 'object' => 'product']);
        $this->stripe->push('GET', '/v1/prices', $this->priceList([]));
        $this->stripe->push('POST', '/v1/prices', $this->stripePrice());

        $this->artisan('stripe:create-products', ['--mode' => 'test', '--only' => ['meo_premium_3y']])
            ->assertSuccessful();

        $this->assertSame(1, $this->stripe->countFor('post', '/v1/prices'));
        $this->assertNull($other->fresh()->stripe_price_id);
    }
}
