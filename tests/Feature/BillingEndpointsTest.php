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

    public function test_an_admin_starts_a_checkout_session(): void
    {
        $this->mock(BillingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('checkoutUrl')
                ->once()
                ->withArgs(function (Organization $organization, Plan $plan, string $success, string $cancel) {
                    return $organization->is($this->organization)
                        && $plan->is($this->plan)
                        && str_contains($success, '/billing/complete')
                        && str_contains($cancel, '/billing/plans');
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
                ->withArgs(fn (Organization $organization, string $returnUrl) => $organization->is($this->organization)
                    && str_contains($returnUrl, '/billing'))
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
