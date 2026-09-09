<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\Feature;
use App\Jobs\FetchDailyRankingsJob;
use App\Models\Alert;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()
            ->onPlan(Plan::factory()->withFeatures([
                Feature::RankingEnabled->value => true,
                Feature::RankingKeywordLimit->value => 10,
            ])->create())
            ->create();

        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = '', ?Location $location = null): string
    {
        return '/api/v1/locations/'.($location ?? $this->location)->id.'/alerts'.$path;
    }

    protected function alertFor(?Location $location = null, bool $read = false): Alert
    {
        $target = $location ?? $this->location;
        $keyword = Keyword::factory()->forLocation($target)->create();

        return Alert::factory()->forKeyword($keyword)->create(['is_read' => $read]);
    }

    public function test_a_member_lists_the_alerts_newest_first_with_an_unread_count(): void
    {
        $this->alertFor(read: true);
        $newest = $this->alertFor();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'alerts')
            ->assertJsonPath('alerts.0.id', $newest->id)
            ->assertJsonPath('alerts.0.type', AlertType::RankDrop->value)
            ->assertJsonPath('alerts.0.type_label', '順位急落')
            ->assertJsonPath('unread_count', 1);
    }

    public function test_the_list_can_be_narrowed_to_the_unread(): void
    {
        $this->alertFor(read: true);
        $unread = $this->alertFor();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('?unread=1'))
            ->assertOk()
            ->assertJsonCount(1, 'alerts')
            ->assertJsonPath('alerts.0.id', $unread->id);
    }

    public function test_alerts_of_another_store_front_are_not_listed(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);

        $this->alertFor();
        $this->alertFor($other);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'alerts');
    }

    public function test_a_staff_member_marks_an_alert_read(): void
    {
        $alert = $this->alertFor();

        $this->actingAs($this->member('staff'))
            ->patchJson($this->url("/{$alert->id}"), ['is_read' => true])
            ->assertOk()
            ->assertJsonPath('alert.is_read', true);

        $this->assertTrue($alert->fresh()->is_read);
    }

    public function test_a_viewer_cannot_clear_an_alert(): void
    {
        $alert = $this->alertFor();

        $this->actingAs($this->member('viewer'))
            ->patchJson($this->url("/{$alert->id}"), ['is_read' => true])
            ->assertForbidden();

        $this->assertFalse($alert->fresh()->is_read);
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreign))
            ->assertNotFound();
    }

    public function test_a_store_manager_checks_one_keyword_now(): void
    {
        Queue::fake();

        $keyword = Keyword::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson("/api/v1/locations/{$this->location->id}/keywords/{$keyword->id}/check")
            ->assertAccepted();

        // The manual check uses the same job as the nightly sweep, so the two
        // cannot drift apart.
        Queue::assertPushed(
            FetchDailyRankingsJob::class,
            fn (FetchDailyRankingsJob $job) => $job->keyword->is($keyword),
        );
    }

    public function test_a_viewer_cannot_trigger_a_check(): void
    {
        Queue::fake();

        $keyword = Keyword::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('viewer'))
            ->postJson("/api/v1/locations/{$this->location->id}/keywords/{$keyword->id}/check")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
