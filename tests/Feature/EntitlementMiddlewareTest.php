<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\UsageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EntitlementMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'tenant', 'feature:instagram.enabled'])
            ->get('/test/instagram', fn () => response()->json(['ok' => true]));

        Route::middleware(['web', 'tenant', 'feature:instagram.enabled,instagram.auto_publish.enabled'])
            ->get('/test/instagram-auto', fn () => response()->json(['ok' => true]));

        Route::middleware(['web', 'tenant', 'quota:gbp.post.monthly_limit'])
            ->post('/test/gbp-post', fn () => response()->json(['ok' => true]));

        Route::middleware(['web', 'tenant', 'quota:gbp.post.monthly_limit,5'])
            ->post('/test/gbp-post-bulk', fn () => response()->json(['ok' => true]));
    }

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function memberOfPlan(array $features): User
    {
        $plan = Plan::factory()->withFeatures($features)->create();
        $organization = Organization::factory()->onPlan($plan)->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => 'viewer']);

        return $user;
    }

    protected function tracker(): UsageTracker
    {
        return app(UsageTracker::class);
    }

    public function test_a_plan_that_includes_the_feature_is_let_through(): void
    {
        $user = $this->memberOfPlan([Feature::InstagramEnabled->value => true]);

        $this->actingAs($user)->get('/test/instagram')->assertOk();
    }

    public function test_a_plan_without_the_feature_is_refused_with_an_upgrade_prompt(): void
    {
        $user = $this->memberOfPlan([Feature::InstagramEnabled->value => false]);

        $this->actingAs($user)
            ->getJson('/test/instagram')
            ->assertForbidden()
            ->assertJsonPath('feature', 'instagram.enabled')
            ->assertJsonPath('message', 'ご利用中のプランではこの機能をご利用いただけません。')
            ->assertJsonPath('upgrade.required', true);
    }

    public function test_a_feature_the_plan_never_mentions_is_refused(): void
    {
        $user = $this->memberOfPlan([Feature::RankingEnabled->value => true]);

        $this->actingAs($user)->getJson('/test/instagram')->assertForbidden();
    }

    public function test_every_listed_feature_must_be_granted(): void
    {
        $user = $this->memberOfPlan([
            Feature::InstagramEnabled->value => true,
            Feature::InstagramAutoPublishEnabled->value => false,
        ]);

        $this->actingAs($user)
            ->getJson('/test/instagram-auto')
            ->assertForbidden()
            ->assertJsonPath('feature', 'instagram.auto_publish.enabled');
    }

    public function test_the_prompt_names_the_cheapest_plan_that_grants_the_feature(): void
    {
        $user = $this->memberOfPlan([Feature::InstagramEnabled->value => false]);

        Plan::factory()
            ->withFeatures([Feature::InstagramEnabled->value => true])
            ->create(['code' => 'meo_premium', 'name' => 'MEO PREMIUM', 'price' => 49800]);

        Plan::factory()
            ->withFeatures([Feature::InstagramEnabled->value => true])
            ->create(['code' => 'meo_standard', 'name' => 'MEO STANDARD', 'price' => 29800]);

        $this->actingAs($user)
            ->getJson('/test/instagram')
            ->assertForbidden()
            ->assertJsonPath('upgrade.recommended_plan.code', 'meo_standard')
            ->assertJsonPath('upgrade.recommended_plan.price', 29800)
            ->assertJsonPath('upgrade.cta_label', 'プランをアップグレード')
            ->assertJsonPath('upgrade.headline', '「MEO STANDARD」にアップグレードすると引き続きご利用いただけます。');
    }

    public function test_the_prompt_points_at_support_when_no_plan_grants_the_feature(): void
    {
        $user = $this->memberOfPlan([Feature::InstagramEnabled->value => false]);

        $this->actingAs($user)
            ->getJson('/test/instagram')
            ->assertForbidden()
            ->assertJsonPath('upgrade.recommended_plan', null)
            ->assertJsonPath('upgrade.cta_label', 'サポートに問い合わせる');
    }

    public function test_a_request_within_the_allowance_is_let_through(): void
    {
        $user = $this->memberOfPlan([Feature::GbpPostMonthlyLimit->value => 4]);

        $this->actingAs($user)->post('/test/gbp-post')->assertOk();
    }

    public function test_the_middleware_does_not_spend_the_allowance_itself(): void
    {
        $user = $this->memberOfPlan([Feature::GbpPostMonthlyLimit->value => 4]);
        $organization = $user->organizations()->firstOrFail();

        $this->actingAs($user)->post('/test/gbp-post')->assertOk();

        $this->assertSame(0, $this->tracker()->used(Feature::GbpPostMonthlyLimit, $organization));
        $this->assertDatabaseCount('usage_records', 0);
    }

    public function test_a_spent_allowance_is_refused_with_the_counters(): void
    {
        $user = $this->memberOfPlan([Feature::GbpPostMonthlyLimit->value => 4]);
        $organization = $user->organizations()->firstOrFail();

        $this->tracker()->record(Feature::GbpPostMonthlyLimit, 4, $organization);

        $this->actingAs($user)
            ->postJson('/test/gbp-post')
            ->assertForbidden()
            ->assertJsonPath('feature', 'gbp.post.monthly_limit')
            ->assertJsonPath('limit', 4)
            ->assertJsonPath('used', 4)
            ->assertJsonPath('remaining', 0)
            ->assertJsonPath('upgrade.required', true);
    }

    public function test_the_amount_a_request_will_consume_is_taken_into_account(): void
    {
        $user = $this->memberOfPlan([Feature::GbpPostMonthlyLimit->value => 4]);

        // Four left, but the route consumes five.
        $this->actingAs($user)->postJson('/test/gbp-post-bulk')->assertForbidden();
        $this->actingAs($user)->postJson('/test/gbp-post')->assertOk();
    }

    public function test_an_unlimited_allowance_is_never_refused(): void
    {
        $user = $this->memberOfPlan([Feature::GbpPostMonthlyLimit->value => null]);
        $organization = $user->organizations()->firstOrFail();

        $this->tracker()->record(Feature::GbpPostMonthlyLimit, 1000, $organization);

        $this->actingAs($user)->post('/test/gbp-post')->assertOk();
    }

    public function test_a_metered_feature_the_plan_omits_has_no_allowance(): void
    {
        $user = $this->memberOfPlan([Feature::RankingEnabled->value => true]);

        $this->actingAs($user)
            ->postJson('/test/gbp-post')
            ->assertForbidden()
            ->assertJsonPath('limit', 0);
    }
}
