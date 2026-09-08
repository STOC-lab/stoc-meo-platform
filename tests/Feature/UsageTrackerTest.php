<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected UsageTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracker = app(UsageTracker::class);
    }

    protected function organizationWith(array $features): Organization
    {
        $plan = Plan::factory()->withFeatures($features)->create();

        return Organization::factory()->onPlan($plan)->create();
    }

    public function test_recording_usage_increments_the_period_counter(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);

        $this->tracker->record(Feature::AiPostsMonthly, 1, $organization);
        $this->tracker->record(Feature::AiPostsMonthly, 3, $organization);

        $this->assertSame(4, $this->tracker->used(Feature::AiPostsMonthly, $organization));
        $this->assertSame(6, $this->tracker->remaining(Feature::AiPostsMonthly, $organization));
        $this->assertDatabaseCount('usage_records', 1);
    }

    public function test_usage_is_isolated_per_organization(): void
    {
        $first = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);
        $second = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);

        $this->tracker->record(Feature::AiPostsMonthly, 5, $first);

        $this->assertSame(5, $this->tracker->used(Feature::AiPostsMonthly, $first));
        $this->assertSame(0, $this->tracker->used(Feature::AiPostsMonthly, $second));
    }

    public function test_counters_start_again_in_the_next_period(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);

        $this->travelTo(now()->startOfMonth()->addDays(3));
        $this->tracker->record(Feature::AiPostsMonthly, 7, $organization);
        $this->assertSame(7, $this->tracker->used(Feature::AiPostsMonthly, $organization));

        $this->travelTo(now()->addMonth()->startOfMonth());
        $this->assertSame(0, $this->tracker->used(Feature::AiPostsMonthly, $organization));
        $this->assertSame(10, $this->tracker->remaining(Feature::AiPostsMonthly, $organization));

        $this->travelBack();
    }

    public function test_consume_refuses_to_exceed_the_limit(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 2]);

        $this->tracker->consume(Feature::AiPostsMonthly, 2, $organization);

        $this->assertFalse($this->tracker->canUse(Feature::AiPostsMonthly, 1, $organization));

        $this->expectException(QuotaExceededException::class);

        $this->tracker->consume(Feature::AiPostsMonthly, 1, $organization);
    }

    public function test_usage_is_not_recorded_when_consume_is_refused(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 1]);

        try {
            $this->tracker->consume(Feature::AiPostsMonthly, 5, $organization);
        } catch (QuotaExceededException $e) {
            $this->assertSame(Feature::AiPostsMonthly->value, $e->feature);
            $this->assertSame(1, $e->limit);
        }

        $this->assertSame(0, $this->tracker->used(Feature::AiPostsMonthly, $organization));
    }

    public function test_a_feature_the_plan_omits_has_no_allowance(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);

        $this->assertSame(0, $this->tracker->remaining(Feature::AiRepliesMonthly, $organization));
        $this->assertFalse($this->tracker->canUse(Feature::AiRepliesMonthly, 1, $organization));
    }

    public function test_an_unlimited_feature_never_runs_out(): void
    {
        $organization = $this->organizationWith([Feature::AiRepliesMonthly->value => null]);

        $this->tracker->consume(Feature::AiRepliesMonthly, 1000, $organization);

        $this->assertNull($this->tracker->remaining(Feature::AiRepliesMonthly, $organization));
        $this->assertTrue($this->tracker->canUse(Feature::AiRepliesMonthly, 10_000, $organization));
    }

    public function test_reset_clears_the_current_period(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);

        $this->tracker->record(Feature::AiPostsMonthly, 4, $organization);
        $this->tracker->reset(Feature::AiPostsMonthly, $organization);

        $this->assertSame(0, $this->tracker->used(Feature::AiPostsMonthly, $organization));
    }

    public function test_the_summary_covers_metered_features_only(): void
    {
        $organization = $this->organizationWith([
            Feature::AiPostsMonthly->value => 10,
            Feature::AiRepliesMonthly->value => null,
            Feature::LocationsMax->value => 3,
            Feature::CsvExport->value => true,
        ]);

        $this->tracker->record(Feature::AiPostsMonthly, 2, $organization);

        $summary = $this->tracker->summary($organization);

        $this->assertSame(
            [Feature::AiPostsMonthly->value, Feature::AiRepliesMonthly->value],
            array_keys($summary),
        );
        $this->assertSame(['used' => 2, 'limit' => 10, 'remaining' => 8], $summary[Feature::AiPostsMonthly->value]);
        $this->assertSame(['used' => 0, 'limit' => null, 'remaining' => null], $summary[Feature::AiRepliesMonthly->value]);
    }

    public function test_it_falls_back_to_the_active_tenant(): void
    {
        $organization = $this->organizationWith([Feature::AiPostsMonthly->value => 10]);

        app(Tenancy::class)->set($organization);

        $this->tracker->record(Feature::AiPostsMonthly);

        $this->assertSame(1, $this->tracker->used(Feature::AiPostsMonthly));
    }
}
