<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class OrganizationBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_bills_the_organization(): void
    {
        $this->assertSame(Organization::class, Cashier::$customerModel);
    }

    public function test_the_billable_columns_live_on_organizations(): void
    {
        $this->assertTrue(Schema::hasColumns('organizations', [
            'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at',
        ]));

        $this->assertFalse(Schema::hasColumn('users', 'stripe_id'));
        $this->assertTrue(Schema::hasColumn('subscriptions', 'organization_id'));
        $this->assertFalse(Schema::hasColumn('subscriptions', 'user_id'));
    }

    public function test_an_organization_exposes_its_stripe_customer_id(): void
    {
        $organization = Organization::factory()->create(['stripe_id' => 'cus_test123']);

        $this->assertSame('cus_test123', $organization->stripeId());
        $this->assertSame('cus_test123', $organization->stripe_customer_id);
        $this->assertTrue($organization->hasStripeId());
    }

    public function test_a_subscription_belongs_back_to_its_organization(): void
    {
        $organization = Organization::factory()->create(['stripe_id' => 'cus_test123']);

        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'type' => 'default',
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test123',
            'quantity' => 1,
        ]);

        $this->assertTrue($subscription->owner->is($organization));
        $this->assertTrue($organization->subscriptions()->first()->is($subscription));
        $this->assertTrue($organization->subscribed('default'));
    }

    public function test_users_are_no_longer_billable(): void
    {
        $this->assertFalse(method_exists(User::class, 'stripeId'));
    }
}
