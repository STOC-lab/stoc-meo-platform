<?php

namespace Tests\Feature\Api;

use App\Enums\Feature;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a plan does not stretch to, and what the API says about it.
 *
 * Two middleware do this work: `feature:<key>` refuses a route the plan does
 * not include at all, and `quota:<key>` refuses one whose allowance for the
 * month is spent. Both answer 403 with the same envelope, so the SPA renders
 * one upgrade prompt wherever the refusal came from.
 *
 * The envelope is the contract this file holds: `message` and `feature`
 * always, `limit`/`used`/`remaining` when an allowance was spent, and
 * `upgrade` so the reader is told what to do about it.
 */
class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function member(string $role = 'org_admin'): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * @param  array<string, int|bool|string|null>  $features
     */
    protected function onPlanWith(array $features): void
    {
        $plan = Plan::factory()->withFeatures($features)->create();

        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();
    }

    protected function url(string $path): string
    {
        return "/api/v1/locations/{$this->location->id}/".ltrim($path, '/');
    }

    public function test_a_feature_the_plan_omits_is_refused_with_its_key(): void
    {
        // An Instagram-only plan does not track ranks.
        $this->onPlanWith([Feature::InstagramEnabled->value => true]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('keywords'))
            ->assertForbidden()
            ->assertJsonPath('feature', 'ranking.enabled')
            ->assertJsonPath('message', 'ご利用中のプランではこの機能をご利用いただけません。');
    }

    public function test_a_plan_that_includes_it_is_let_through(): void
    {
        $this->onPlanWith([
            Feature::RankingEnabled->value => true,
            Feature::RankingKeywordLimit->value => 10,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('keywords'))
            ->assertOk()
            ->assertJsonStructure(['keywords', 'allowance']);
    }

    public function test_the_refusal_carries_what_to_do_about_it(): void
    {
        $this->onPlanWith([Feature::InstagramEnabled->value => true]);

        $response = $this->actingAs($this->member('viewer'))
            ->getJson($this->url('keywords'))
            ->assertForbidden()
            ->assertJsonStructure(['message', 'feature', 'upgrade' => ['required', 'headline', 'cta_label', 'cta_url']]);

        $this->assertTrue($response->json('upgrade.required'));
    }

    public function test_a_limit_of_zero_is_a_feature_the_plan_does_not_include(): void
    {
        // The free plan's PDF reports are zero rather than false; allows()
        // reads a limit of zero as not granted, and the middleware refuses.
        $this->onPlanWith([Feature::PdfReportEnabled->value => false]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('reports'))
            ->assertForbidden()
            ->assertJsonPath('feature', 'pdf_report.enabled');
    }

    public function test_a_spent_allowance_says_how_much_was_spent(): void
    {
        $this->onPlanWith([
            Feature::RankingEnabled->value => true,
            Feature::RankingKeywordLimit->value => 1,
        ]);

        Keyword::factory()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url('keywords'), ['keyword' => '姫路 MEO'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'ranking.keyword_limit')
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('used', 1)
            ->assertJsonPath('remaining', 0)
            ->assertJsonStructure(['message', 'feature', 'limit', 'used', 'remaining', 'upgrade']);
    }

    public function test_an_unlimited_allowance_is_never_refused(): void
    {
        $this->onPlanWith([
            Feature::RankingEnabled->value => true,
            Feature::RankingKeywordLimit->value => null,
        ]);

        Keyword::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url('keywords'), ['keyword' => '姫路 MEO'])
            ->assertCreated();
    }

    public function test_a_campaign_may_not_name_a_channel_the_plan_does_not_carry(): void
    {
        // Business Profile posting but no Instagram: a campaign asking for
        // both is refused, and the refusal names the half that is missing.
        $this->onPlanWith([
            Feature::GbpPostMonthlyLimit->value => 4,
            Feature::InstagramEnabled->value => false,
        ]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url('campaigns'), [
                'name' => '春のキャンペーン',
                'campaign_type' => 'manual',
                'theme' => '新メニュー',
                'channels' => ['gbp', 'instagram'],
            ])
            ->assertForbidden()
            ->assertJsonPath('feature', 'instagram.enabled');

        $this->assertSame(0, $this->location->campaigns()->count());
    }

    public function test_a_campaign_on_a_channel_the_plan_carries_is_created(): void
    {
        $this->onPlanWith([Feature::GbpPostMonthlyLimit->value => 4]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url('campaigns'), [
                'name' => '春のキャンペーン',
                'campaign_type' => 'manual',
                'theme' => '新メニュー',
                'channels' => ['gbp'],
            ])
            ->assertCreated();

        $this->assertSame(1, $this->location->campaigns()->count());
    }

    public function test_a_channel_no_plan_carries_is_refused(): void
    {
        // WordPress is defined and granted by nothing, so it is refused on
        // every plan rather than silently queueing a job that cannot run.
        $this->onPlanWith([Feature::GbpPostMonthlyLimit->value => 4]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url('campaigns'), [
                'name' => '春のキャンペーン',
                'campaign_type' => 'manual',
                'theme' => '新メニュー',
                'channels' => ['wordpress'],
            ])
            ->assertForbidden()
            ->assertJsonPath('feature', 'blog.enabled');
    }

    public function test_an_organization_with_no_plan_is_refused_rather_than_allowed(): void
    {
        $this->organization->forceFill(['plan_id' => null])->save();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('keywords'))
            ->assertForbidden()
            ->assertJsonPath('feature', 'ranking.enabled');
    }

    public function test_a_refusal_is_the_plans_and_not_the_roles(): void
    {
        // A 403 from the permissions carries no `feature`, which is how the
        // SPA tells an upgrade prompt from "ask your administrator".
        $this->onPlanWith([
            Feature::RankingEnabled->value => true,
            Feature::RankingKeywordLimit->value => 10,
        ]);

        $response = $this->actingAs($this->member('viewer'))
            ->postJson($this->url('keywords'), ['keyword' => '姫路 MEO'])
            ->assertForbidden();

        $this->assertNull($response->json('feature'));
    }
}
