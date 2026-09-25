<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class BillingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::factory()->create([
            'code' => 'meo_standard',
            'stripe_price_id' => 'price_standard',
        ]);

        $this->organization = Organization::factory()->create();
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_an_admin_lists_the_plans_on_sale(): void
    {
        $this->plan->update(['is_active' => false]);
        $twoYear = Plan::factory()->create([
            'code' => 'meo_premium_2y',
            'name' => 'MEO PREMIUM 2年',
            'description' => 'MEO PREMIUM 2年契約。',
            'price' => 324000,
            'interval' => Plan::INTERVAL_YEAR,
            'billing_period_months' => 24,
            'phases' => 2,
            'stripe_price_id' => 'price_2y',
            'sort_order' => 20,
        ]);
        $twoYear->features()->createMany([
            ['key' => 'ranking.keyword_limit', 'type' => 'limit', 'value' => '30'],
            ['key' => 'aio.report.enabled', 'type' => 'boolean', 'value' => '1'],
        ]);
        Plan::factory()->create(['code' => 'meo_premium_1y', 'stripe_price_id' => 'price_1y', 'sort_order' => 10]);
        Plan::factory()->create(['code' => 'meo_free', 'price' => 0, 'stripe_price_id' => null]);
        Plan::factory()->create([
            'code' => 'meo_premium_6m',
            'interval' => Plan::INTERVAL_ONE_TIME,
            'stripe_price_id' => 'price_6m',
        ]);

        $response = $this->actingAs($this->member('org_admin'))->getJson('/api/v1/billing/plans');

        $response->assertOk();
        $this->assertSame(['meo_premium_1y', 'meo_premium_2y'], $response->json('plans.*.code'));
        $this->assertSame([
            'id' => $twoYear->id,
            'code' => 'meo_premium_2y',
            'name' => 'MEO PREMIUM 2年',
            'product' => 'meo',
            'description' => 'MEO PREMIUM 2年契約。',
            'price' => 324000,
            'interval' => 'year',
            'monthly_price' => 27000,
            'billing_period_months' => 24,
            'phases' => 2,
            'features' => ['aio.report.enabled' => true, 'ranking.keyword_limit' => 30],
        ], $response->json('plans.1'));
    }

    public function test_an_editor_cannot_list_the_plans_on_sale(): void
    {
        $this->actingAs($this->member('staff'))
            ->getJson('/api/v1/billing/plans')
            ->assertForbidden();
    }

    public function test_an_admin_starts_a_checkout_session(): void
    {
        $this->mock(BillingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkoutUrl')
                ->once()
                ->withArgs(function (Organization $organization, Plan $plan, string $success, string $cancel) {
                    // Both have to be routes the SPA has. Billing is a tab of
                    // /settings, and a path it does not know answers with the
                    // not-found page to someone who has just paid.
                    return $organization->is($this->organization)
                        && $plan->is($this->plan)
                        && $success === config('app.url').'/settings?tab=billing&checkout=success'
                        && $cancel === config('app.url').'/settings?tab=billing&checkout=cancelled';
                })
                ->andReturn('https://checkout.stripe.com/c/pay/cs_test123');
        });

        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/billing/checkout', ['plan_code' => 'meo_standard'])
            ->assertOk()
            ->assertExactJson(['url' => 'https://checkout.stripe.com/c/pay/cs_test123']);
    }

    public function test_checkout_accepts_caller_supplied_urls(): void
    {
        $this->mock(BillingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkoutUrl')
                ->once()
                ->withArgs(fn ($organization, $plan, string $success, string $cancel) => $success === 'https://app.example.com/ok'
                    && $cancel === 'https://app.example.com/no')
                ->andReturn('https://checkout.stripe.com/c/pay/cs_test123');
        });

        $this->actingAs($this->member('owner'))
            ->postJson('/api/v1/billing/checkout', [
                'plan_code' => 'meo_standard',
                'success_url' => 'https://app.example.com/ok',
                'cancel_url' => 'https://app.example.com/no',
            ])
            ->assertOk();
    }

    public function test_an_editor_cannot_start_a_checkout_session(): void
    {
        $this->mock(BillingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('checkoutUrl'));

        $this->actingAs($this->member('staff'))
            ->postJson('/api/v1/billing/checkout', ['plan_code' => 'meo_standard'])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_start_a_checkout_session(): void
    {
        $this->postJson('/api/v1/billing/checkout', ['plan_code' => 'meo_standard'])
            ->assertUnauthorized();
    }

    public function test_a_non_member_cannot_reach_the_organization(): void
    {
        $this->member('owner');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/billing/checkout', ['plan_code' => 'meo_standard'])
            ->assertForbidden();
    }

    public function test_an_unknown_plan_is_rejected(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/billing/checkout', ['plan_code' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_code');
    }

    public function test_a_plan_without_a_stripe_price_cannot_be_bought(): void
    {
        Plan::factory()->create(['code' => 'meo_free', 'stripe_price_id' => null]);

        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/billing/checkout', ['plan_code' => 'meo_free'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_code');
    }

    public function test_an_inactive_plan_cannot_be_bought(): void
    {
        Plan::factory()->create([
            'code' => 'meo_retired',
            'stripe_price_id' => 'price_retired',
            'is_active' => false,
        ]);

        $this->actingAs($this->member('org_admin'))
            ->postJson('/api/v1/billing/checkout', ['plan_code' => 'meo_retired'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_code');
    }

    public function test_an_admin_gets_a_customer_portal_link(): void
    {
        $this->mock(BillingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('portalUrl')
                ->once()
                // The portal's way back has to be a route the SPA has too.
                ->withArgs(fn (Organization $organization, string $returnUrl) => $organization->is($this->organization)
                    && $returnUrl === config('app.url').'/settings?tab=billing')
                ->andReturn('https://billing.stripe.com/p/session/test123');
        });

        $this->actingAs($this->member('org_admin'))
            ->getJson('/api/v1/billing/portal')
            ->assertOk()
            ->assertExactJson(['url' => 'https://billing.stripe.com/p/session/test123']);
    }

    public function test_a_viewer_cannot_open_the_customer_portal(): void
    {
        $this->mock(BillingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('portalUrl'));

        $this->actingAs($this->member('viewer'))
            ->getJson('/api/v1/billing/portal')
            ->assertForbidden();
    }
}
