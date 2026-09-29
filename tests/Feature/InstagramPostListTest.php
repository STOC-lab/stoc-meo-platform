<?php

namespace Tests\Feature;

use App\Enums\CampaignChannel;
use App\Enums\Feature;
use App\Models\ContentCampaign;
use App\Models\ContentCampaignPost;
use App\Models\InstagramAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\UsageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstagramPostListTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()
            ->onPlan(Plan::factory()->withFeatures([
                Feature::InstagramEnabled->value => true,
                Feature::InstagramPostMonthlyLimit->value => 12,
            ])->create())
            ->create();

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => '渋谷店',
        ]);
    }

    protected function viewer(): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => 'viewer']);

        return $user;
    }

    protected function campaign(?Location $location = null): ContentCampaign
    {
        return ContentCampaign::factory()->forLocation($location ?? $this->location)->create();
    }

    public function test_it_lists_only_instagram_posts_with_their_store_front(): void
    {
        $campaign = $this->campaign();
        $instagram = ContentCampaignPost::factory()->forCampaign($campaign)->published()->create();
        ContentCampaignPost::factory()->forCampaign($campaign)->channel(CampaignChannel::Gbp)->published()->create();

        $this->actingAs($this->viewer())
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', $instagram->id)
            ->assertJsonPath('posts.0.status', 'published')
            ->assertJsonPath('posts.0.status_label', '公開済み')
            ->assertJsonPath('posts.0.location.name', '渋谷店')
            ->assertJsonPath('posts.0.posted_at', $instagram->published_at->toIso8601String());
    }

    public function test_the_excerpt_is_cut_to_a_hundred_characters(): void
    {
        ContentCampaignPost::factory()
            ->forCampaign($this->campaign())
            ->awaitingApproval(str_repeat('あ', 150))
            ->create();

        $excerpt = $this->actingAs($this->viewer())
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->json('posts.0.excerpt');

        $this->assertSame(str_repeat('あ', 100).'…', $excerpt);
    }

    public function test_an_unpublished_post_is_dated_by_when_it_was_created(): void
    {
        $post = ContentCampaignPost::factory()->forCampaign($this->campaign())->create();

        $this->actingAs($this->viewer())
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->assertJsonPath('posts.0.published_at', null)
            ->assertJsonPath('posts.0.posted_at', $post->created_at->toIso8601String());
    }

    public function test_it_pages_fifteen_at_a_time(): void
    {
        // A campaign carries one post per channel, so each post needs its own.
        foreach (range(1, 16) as $ignored) {
            ContentCampaignPost::factory()->forCampaign($this->campaign())->create();
        }

        $this->actingAs($this->viewer())
            ->getJson('/api/v1/instagram/posts?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16);
    }

    public function test_it_reports_this_months_allowance(): void
    {
        app(UsageTracker::class)->record(Feature::InstagramPostMonthlyLimit, 3, $this->organization);

        $this->actingAs($this->viewer())
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->assertJsonPath('allowance.limit', 12)
            ->assertJsonPath('allowance.used', 3)
            ->assertJsonPath('allowance.remaining', 9);
    }

    public function test_it_says_whether_an_instagram_account_is_connected(): void
    {
        $user = $this->viewer();

        $this->actingAs($user)
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->assertJsonPath('connected', false);

        InstagramAccount::factory()->forLocation($this->location)->create();

        $this->actingAs($user)
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->assertJsonPath('connected', true);
    }

    public function test_another_organizations_posts_and_connections_stay_out_of_it(): void
    {
        $otherLocation = Location::factory()->create();
        ContentCampaignPost::factory()->forCampaign($this->campaign($otherLocation))->published()->create();
        InstagramAccount::factory()->forLocation($otherLocation)->create();

        $this->actingAs($this->viewer())
            ->getJson('/api/v1/instagram/posts')
            ->assertOk()
            ->assertJsonCount(0, 'posts')
            ->assertJsonPath('connected', false);
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->getJson('/api/v1/instagram/posts')->assertUnauthorized();
    }
}
