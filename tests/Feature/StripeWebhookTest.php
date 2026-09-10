<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Signature verification is covered by its own test below; the rest
        // post plain payloads.
        config(['cashier.webhook.secret' => null]);
    }

    protected function plan(string $code, string $priceId): Plan
    {
        return Plan::factory()->create([
            'code' => $code,
            'stripe_price_id' => $priceId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function event(string $type, array $object): array
    {
        return [
            'id' => 'evt_'.fake()->unique()->numerify('##########'),
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscriptionObject(string $customer, string $priceId, array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub_test123',
            'customer' => $customer,
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => ['type' => 'default'],
            'items' => [
                'data' => [[
                    'id' => 'si_test123',
                    'quantity' => 1,
                    'price' => ['id' => $priceId, 'product' => 'prod_test123'],
                ]],
            ],
        ], $overrides);
    }

    public function test_a_completed_checkout_activates_the_plan(): void
    {
        $plan = $this->plan('meo_standard', 'price_standard');
        $organization = Organization::factory()->create(['status' => Organization::STATUS_SUSPENDED]);

        $this->postJson('/stripe/webhook', $this->event('checkout.session.completed', [
            'id' => 'cs_test123',
            'customer' => 'cus_test123',
            'subscription' => 'sub_test123',
            'metadata' => [
                'organization_id' => (string) $organization->id,
                'plan_id' => (string) $plan->id,
            ],
        ]))->assertOk();

        $organization->refresh();

        $this->assertSame('cus_test123', $organization->stripe_id);
        $this->assertSame('sub_test123', $organization->stripe_subscription_id);
        $this->assertSame($plan->id, $organization->plan_id);
        $this->assertSame(Organization::STATUS_ACTIVE, $organization->status);
    }

    public function test_a_completed_checkout_falls_back_to_the_stripe_customer(): void
    {
        $organization = Organization::factory()->create(['stripe_id' => 'cus_known']);

        $this->postJson('/stripe/webhook', $this->event('checkout.session.completed', [
            'id' => 'cs_test123',
            'customer' => 'cus_known',
            'subscription' => 'sub_test123',
            'metadata' => [],
        ]))->assertOk();

        $this->assertSame('sub_test123', $organization->refresh()->stripe_subscription_id);
    }

    public function test_an_unknown_customer_is_ignored(): void
    {
        $this->postJson('/stripe/webhook', $this->event('checkout.session.completed', [
            'id' => 'cs_test123',
            'customer' => 'cus_stranger',
            'subscription' => 'sub_test123',
            'metadata' => [],
        ]))->assertOk();

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_a_paid_invoice_clears_a_past_due_organization(): void
    {
        $organization = Organization::factory()->create([
            'stripe_id' => 'cus_test123',
            'status' => Organization::STATUS_PAST_DUE,
        ]);

        $this->postJson('/stripe/webhook', $this->event('invoice.paid', [
            'id' => 'in_test123',
            'customer' => 'cus_test123',
        ]))->assertOk();

        $this->assertSame(Organization::STATUS_ACTIVE, $organization->refresh()->status);
    }

    public function test_a_paid_invoice_leaves_a_trialing_organization_alone(): void
    {
        $organization = Organization::factory()->create([
            'stripe_id' => 'cus_test123',
            'status' => Organization::STATUS_TRIALING,
        ]);

        $this->postJson('/stripe/webhook', $this->event('invoice.paid', [
            'id' => 'in_test123',
            'customer' => 'cus_test123',
        ]))->assertOk();

        $this->assertSame(Organization::STATUS_TRIALING, $organization->refresh()->status);
    }

    public function test_a_failed_payment_holds_the_organization(): void
    {
        $organization = Organization::factory()->create([
            'stripe_id' => 'cus_test123',
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $this->postJson('/stripe/webhook', $this->event('invoice.payment_failed', [
            'id' => 'in_test123',
            'customer' => 'cus_test123',
        ]))->assertOk();

        $this->assertSame(Organization::STATUS_PAST_DUE, $organization->refresh()->status);
    }

    public function test_a_failed_payment_does_not_revive_a_cancelled_organization(): void
    {
        $organization = Organization::factory()->create([
            'stripe_id' => 'cus_test123',
            'status' => Organization::STATUS_CANCELED,
        ]);

        $this->postJson('/stripe/webhook', $this->event('invoice.payment_failed', [
            'id' => 'in_test123',
            'customer' => 'cus_test123',
        ]))->assertOk();

        $this->assertSame(Organization::STATUS_CANCELED, $organization->refresh()->status);
    }

    public function test_a_subscription_update_moves_the_organization_to_the_new_plan(): void
    {
        $current = $this->plan('meo_light', 'price_light');
        $upgraded = $this->plan('meo_premium', 'price_premium');

        $organization = Organization::factory()->onPlan($current)->create([
            'stripe_id' => 'cus_test123',
        ]);

        $this->postJson('/stripe/webhook', $this->event(
            'customer.subscription.updated',
            $this->subscriptionObject('cus_test123', 'price_premium'),
        ))->assertOk();

        $organization->refresh();

        $this->assertSame($upgraded->id, $organization->plan_id);
        $this->assertSame('sub_test123', $organization->stripe_subscription_id);
        $this->assertSame(Organization::STATUS_ACTIVE, $organization->status);

        // Cashier's own bookkeeping still runs.
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $organization->id,
            'stripe_id' => 'sub_test123',
            'stripe_price' => 'price_premium',
            'stripe_status' => 'active',
        ]);
    }

    public function test_a_new_subscription_moves_the_organization_onto_the_plan_it_paid_for(): void
    {
        $free = $this->plan('meo_free', 'price_free');
        $bought = $this->plan('meo_light', 'price_light');

        $organization = Organization::factory()->onPlan($free)->create([
            'stripe_id' => 'cus_test123',
        ]);

        // Checkout does not reliably follow with customer.subscription.updated,
        // so created has to be enough on its own.
        $this->postJson('/stripe/webhook', $this->event(
            'customer.subscription.created',
            $this->subscriptionObject('cus_test123', 'price_light'),
        ))->assertOk();

        $organization->refresh();

        $this->assertSame($bought->id, $organization->plan_id);
        $this->assertSame('sub_test123', $organization->stripe_subscription_id);
        $this->assertSame(Organization::STATUS_ACTIVE, $organization->status);

        // Cashier's own bookkeeping still runs.
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $organization->id,
            'stripe_id' => 'sub_test123',
            'stripe_price' => 'price_light',
            'stripe_status' => 'active',
        ]);
    }

    public function test_a_past_due_subscription_holds_the_organization(): void
    {
        $plan = $this->plan('meo_light', 'price_light');
        $organization = Organization::factory()->onPlan($plan)->create(['stripe_id' => 'cus_test123']);

        $this->postJson('/stripe/webhook', $this->event(
            'customer.subscription.updated',
            $this->subscriptionObject('cus_test123', 'price_light', ['status' => 'past_due']),
        ))->assertOk();

        $this->assertSame(Organization::STATUS_PAST_DUE, $organization->refresh()->status);
    }

    public function test_an_unknown_price_leaves_the_plan_untouched(): void
    {
        $plan = $this->plan('meo_light', 'price_light');
        $organization = Organization::factory()->onPlan($plan)->create(['stripe_id' => 'cus_test123']);

        $this->postJson('/stripe/webhook', $this->event(
            'customer.subscription.updated',
            $this->subscriptionObject('cus_test123', 'price_not_seeded'),
        ))->assertOk();

        $this->assertSame($plan->id, $organization->refresh()->plan_id);
    }

    public function test_a_deleted_subscription_drops_back_to_the_free_plan(): void
    {
        $free = $this->plan('meo_free', 'price_free');
        $paid = $this->plan('meo_premium', 'price_premium');

        $organization = Organization::factory()->onPlan($paid)->create([
            'stripe_id' => 'cus_test123',
            'stripe_subscription_id' => 'sub_test123',
        ]);

        Subscription::create([
            'organization_id' => $organization->id,
            'type' => 'default',
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_premium',
            'quantity' => 1,
        ]);

        $this->postJson('/stripe/webhook', $this->event(
            'customer.subscription.deleted',
            $this->subscriptionObject('cus_test123', 'price_premium', ['status' => 'canceled']),
        ))->assertOk();

        $organization->refresh();

        $this->assertSame($free->id, $organization->plan_id);
        $this->assertNull($organization->stripe_subscription_id);
        $this->assertSame(Organization::STATUS_CANCELED, $organization->status);
        $this->assertFalse($organization->subscribed('default'));
    }

    public function test_an_unhandled_event_is_acknowledged(): void
    {
        $this->postJson('/stripe/webhook', $this->event('customer.discount.created', [
            'id' => 'di_test123',
        ]))->assertOk();
    }

    public function test_the_signature_is_verified_when_a_secret_is_configured(): void
    {
        config(['cashier.webhook.secret' => 'whsec_dummy']);

        $this->postJson('/stripe/webhook', $this->event('invoice.paid', [
            'id' => 'in_test123',
            'customer' => 'cus_test123',
        ]))->assertForbidden();
    }
}
