<?php

namespace Tests\Feature;

use App\Enums\HeatmapGridSize;
use App\Jobs\CalculateMEOScoreJob;
use App\Models\GbpPost;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\MeoScore;
use App\Models\Organization;
use App\Models\RankingResult;
use App\Models\Review;
use App\Models\User;
use App\Services\MEO\MEOScoreCalculator;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeoScoreTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1',
            'address' => '東京都渋谷区',
            'phone' => '03-1234-5678',
            'website_url' => 'https://example.com',
            'latitude' => 35.658,
            'longitude' => 139.7016,
        ]);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * @return array{score: float, breakdown: array<string, mixed>}
     */
    protected function calculate(): array
    {
        return app(Tenancy::class)->forOrganization(
            $this->organization,
            fn () => app(MEOScoreCalculator::class)->calculate($this->location),
        );
    }

    protected function keywordRanked(int $rank): Keyword
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();

        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => $rank,
            'checked_at' => now()->subDay(),
        ]);

        return $keyword;
    }

    public function test_first_place_scores_full_marks_and_the_floor_scores_nothing(): void
    {
        $this->keywordRanked(1);

        $this->assertSame(100.0, $this->calculate()['breakdown']['ranking']['score']);

        Keyword::acrossTenants()->delete();
        RankingResult::acrossTenants()->delete();

        $this->keywordRanked(MEOScoreCalculator::RANK_FLOOR);

        $this->assertSame(0.0, $this->calculate()['breakdown']['ranking']['score']);
    }

    public function test_a_check_that_found_nothing_counts_as_the_floor_rather_than_being_skipped(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();
        RankingResult::factory()->forKeyword($keyword)->unranked()->create(['checked_at' => now()->subDay()]);

        $ranking = $this->calculate()['breakdown']['ranking'];

        $this->assertTrue($ranking['measured']);
        $this->assertSame(0.0, $ranking['score']);
        $this->assertSame(1, $ranking['detail']['unranked']);
    }

    public function test_only_the_latest_check_of_each_keyword_counts(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();

        RankingResult::factory()->forKeyword($keyword)->create(['rank' => 19, 'checked_at' => now()->subDays(5)]);
        RankingResult::factory()->forKeyword($keyword)->create(['rank' => 1, 'checked_at' => now()->subDay()]);

        $ranking = $this->calculate()['breakdown']['ranking'];

        // A keyword checked daily must not outweigh one checked once.
        $this->assertSame(1, $ranking['detail']['keywords']);
        $this->assertSame(100.0, $ranking['score']);
    }

    public function test_a_component_with_nothing_to_measure_is_left_out_rather_than_scored_zero(): void
    {
        // No keywords, no heatmap, no reviews — only the profile is scorable.
        $result = $this->calculate();

        $this->assertFalse($result['breakdown']['ranking']['measured']);
        $this->assertFalse($result['breakdown']['heatmap']['measured']);
        $this->assertFalse($result['breakdown']['reviews']['measured']);
        $this->assertTrue($result['breakdown']['profile']['measured']);

        // The profile is complete apart from a recent post, so it is 5 of 6 —
        // and because it is the only measured part, it is the whole score.
        $this->assertSame($result['breakdown']['profile']['score'], $result['score']);
        $this->assertGreaterThan(0.0, $result['score']);
    }

    public function test_the_weights_of_what_is_left_are_renormalised(): void
    {
        $this->keywordRanked(1);

        $result = $this->calculate();

        // Ranking is 100 and profile is 5/6; with only those two measured the
        // score is their weighted average over their own weights alone, which
        // is far above what it would be if the missing parts scored zero.
        $expected = round(
            (100.0 * MEOScoreCalculator::WEIGHTS['ranking'] + $result['breakdown']['profile']['score'] * MEOScoreCalculator::WEIGHTS['profile'])
            / (MEOScoreCalculator::WEIGHTS['ranking'] + MEOScoreCalculator::WEIGHTS['profile']),
            1,
        );

        $this->assertSame($expected, $result['score']);
    }

    public function test_a_store_front_with_nothing_at_all_scores_zero_honestly(): void
    {
        $bare = Location::factory()->withoutCoordinates()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => null,
            'address' => null,
            'phone' => null,
            'website_url' => null,
        ]);

        $result = app(Tenancy::class)->forOrganization(
            $this->organization,
            fn () => app(MEOScoreCalculator::class)->calculate($bare),
        );

        $this->assertSame(0.0, $result['score']);
        $this->assertSame(0.0, $result['breakdown']['profile']['score']);
    }

    public function test_the_heatmap_component_weighs_coverage_and_position_together(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();
        $run = HeatmapRun::factory()->forKeyword($keyword)
            ->gridSize(HeatmapGridSize::Grid5x5)->completed()->create();

        // Half the grid ranks, all at first place.
        HeatmapPoint::factory()->forRun($run)->at(0, 0)->create(['rank' => 1]);
        HeatmapPoint::factory()->forRun($run)->at(0, 1)->create(['rank' => 1]);
        HeatmapPoint::factory()->forRun($run)->at(0, 2)->unranked()->create();
        HeatmapPoint::factory()->forRun($run)->at(0, 3)->unranked()->create();

        $heatmap = $this->calculate()['breakdown']['heatmap'];

        $this->assertSame(50.0, $heatmap['detail']['coverage_percent']);
        // 50% coverage × 0.6 + 100 position × 0.4 = 70.
        $this->assertSame(70.0, $heatmap['score']);
    }

    public function test_the_review_component_weighs_the_rating_and_the_reply_rate(): void
    {
        Review::factory()->forLocation($this->location)->rated(5)->answered()->create();
        Review::factory()->forLocation($this->location)->rated(5)->create();

        $reviews = $this->calculate()['breakdown']['reviews'];

        // A five-star average is 100; half answered is 50.
        // 100 × 0.7 + 50 × 0.3 = 85.
        $this->assertSame(85.0, $reviews['score']);
        $this->assertSame(1, $reviews['detail']['unanswered']);
    }

    public function test_a_one_star_average_scores_nothing_on_rating(): void
    {
        Review::factory()->forLocation($this->location)->rated(1)->answered()->create();

        $reviews = $this->calculate()['breakdown']['reviews'];

        // Rating 0 × 0.7 + fully answered 100 × 0.3 = 30.
        $this->assertSame(30.0, $reviews['score']);
    }

    public function test_the_profile_component_counts_what_is_filled_in(): void
    {
        GbpPost::factory()->forLocation($this->location)->published()->create([
            'published_at' => now()->subDays(3),
        ]);

        $profile = $this->calculate()['breakdown']['profile'];

        $this->assertSame(6, $profile['detail']['met']);
        $this->assertSame(100.0, $profile['score']);
        $this->assertTrue($profile['detail']['checks']['posted_recently']);
    }

    public function test_a_post_older_than_a_month_does_not_count_as_keeping_the_profile_alive(): void
    {
        GbpPost::factory()->forLocation($this->location)->published()->create([
            'published_at' => now()->subDays(60),
        ]);

        $this->assertFalse($this->calculate()['breakdown']['profile']['detail']['checks']['posted_recently']);
    }

    public function test_a_failed_post_does_not_count(): void
    {
        GbpPost::factory()->forLocation($this->location)->failed()->create();

        $this->assertFalse($this->calculate()['breakdown']['profile']['detail']['checks']['posted_recently']);
    }

    public function test_the_job_stores_the_score_with_its_working(): void
    {
        $this->keywordRanked(3);

        (new CalculateMEOScoreJob($this->location))->handle(
            app(MEOScoreCalculator::class),
            app(Tenancy::class),
        );

        $score = MeoScore::acrossTenants()->firstOrFail();

        $this->assertSame($this->organization->id, $score->organization_id);
        $this->assertGreaterThan(0.0, $score->score);
        $this->assertArrayHasKey('ranking', $score->breakdown);
        $this->assertNotNull($score->calculated_at);
    }

    public function test_rescoring_a_day_overwrites_rather_than_adding_a_second_row(): void
    {
        $this->keywordRanked(3);

        (new CalculateMEOScoreJob($this->location))->handle(app(MEOScoreCalculator::class), app(Tenancy::class));
        (new CalculateMEOScoreJob($this->location))->handle(app(MEOScoreCalculator::class), app(Tenancy::class));

        $this->assertSame(1, MeoScore::acrossTenants()->count());
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        (new CalculateMEOScoreJob($this->location))->handle(app(MEOScoreCalculator::class), app(Tenancy::class));

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_the_weakest_component_is_what_a_proposal_should_start_from(): void
    {
        $score = MeoScore::factory()->forLocation($this->location)->create();

        $this->assertSame('heatmap', array_key_first($score->weakestComponents()));
    }

    public function test_a_member_reads_the_score_with_its_history(): void
    {
        MeoScore::factory()->forLocation($this->location)->on('2026-09-07', 55.5)->create();
        MeoScore::factory()->forLocation($this->location)->on('2026-09-08', 62.5)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/meo-score")
            ->assertOk()
            ->assertJsonPath('score.score', 62.5)
            ->assertJsonCount(2, 'history')
            ->assertJsonPath('history.0.score', 55.5)
            ->assertJsonPath('score.weakest', 'heatmap');
    }

    public function test_a_store_front_that_has_never_been_scored_answers_with_nothing(): void
    {
        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/meo-score")
            ->assertOk()
            ->assertJsonPath('score', null)
            ->assertJsonCount(0, 'history');
    }

    public function test_scores_of_another_organization_are_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$foreign->id}/meo-score")
            ->assertNotFound();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson("/api/v1/locations/{$this->location->id}/meo-score")->assertUnauthorized();
    }
}
