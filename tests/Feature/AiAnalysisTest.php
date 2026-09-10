<?php

namespace Tests\Feature;

use App\Enums\AnalysisType;
use App\Enums\Feature;
use App\Jobs\CalculateMEOScoreJob;
use App\Jobs\GenerateDailyAnalysisJob;
use App\Jobs\GenerateWeeklyAnalysisJob;
use App\Models\Analysis;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\MeoScore;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankingResult;
use App\Models\Review;
use App\Models\User;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Prompts\AnalysisPrompt;
use App\Services\MEO\AnalysisFigures;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every model call in this file is faked; nothing here reaches Anthropic.
 */
class AiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'ai.claude.api_key' => 'sk-ant-test',
            'ai.claude.models' => ['fast' => 'claude-haiku-4-5', 'strong' => 'claude-sonnet-4-6'],
        ]);

        $this->travelTo(CarbonImmutable::create(2026, 9, 9, 6, 0));

        $this->organization = $this->organizationOnPlan([
            Feature::AiWeeklyAnalysisEnabled->value => true,
            Feature::AiDailyAnalysisEnabled->value => true,
        ]);

        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
    }

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function organizationOnPlan(array $features): Organization
    {
        return Organization::factory()
            ->onPlan(Plan::factory()->withFeatures($features)->create())
            ->create();
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function fakeModel(?string $text = null): void
    {
        $text ??= json_encode([
            'summary' => '順位は横ばいですが、口コミの返信率が上がりました。',
            'highlights' => ['返信率が20%改善'],
            'watch' => ['「渋谷 カフェ」が2位下降'],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-4-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 150],
        ])]);
    }

    protected function runWeekly(): void
    {
        (new GenerateWeeklyAnalysisJob($this->location))->handle(
            app(AIProviderFactory::class),
            app(AnalysisPrompt::class),
            app(AnalysisFigures::class),
            app(Tenancy::class),
        );
    }

    protected function runDaily(): void
    {
        (new GenerateDailyAnalysisJob($this->location))->handle(
            app(AIProviderFactory::class),
            app(AnalysisPrompt::class),
            app(AnalysisFigures::class),
            app(Tenancy::class),
        );
    }

    public function test_the_weekly_job_stores_an_analysis_over_the_last_seven_days(): void
    {
        $this->fakeModel();

        $this->runWeekly();

        $analysis = Analysis::acrossTenants()->firstOrFail();

        $this->assertSame(AnalysisType::Weekly, $analysis->type);
        $this->assertSame('順位は横ばいですが、口コミの返信率が上がりました。', $analysis->summary());
        $this->assertSame(['返信率が20%改善'], $analysis->content['highlights']);
        $this->assertSame('claude-haiku-4-5', $analysis->model);

        // The window ends on the last whole day and covers seven of them.
        $this->assertSame('2026-09-08', $analysis->period_end->toDateString());
        $this->assertSame('2026-09-02', $analysis->period_start->toDateString());
    }

    public function test_the_daily_job_covers_one_day(): void
    {
        $this->fakeModel();

        $this->runDaily();

        $analysis = Analysis::acrossTenants()->firstOrFail();

        $this->assertSame(AnalysisType::Daily, $analysis->type);
        $this->assertSame('2026-09-08', $analysis->period_start->toDateString());
        $this->assertSame('2026-09-08', $analysis->period_end->toDateString());
    }

    public function test_the_figures_the_analysis_was_written_from_are_kept_with_it(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();
        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 4,
            'checked_at' => CarbonImmutable::create(2026, 9, 5, 2, 0),
        ]);
        Review::factory()->forLocation($this->location)->rated(5)->create([
            'reviewed_at' => CarbonImmutable::create(2026, 9, 4, 12, 0),
        ]);
        MeoScore::factory()->forLocation($this->location)->on('2026-09-08', 71.5)->create();

        $this->fakeModel();

        $this->runWeekly();

        $figures = Analysis::acrossTenants()->firstOrFail()->content['figures'];

        $this->assertSame(71.5, $figures['score']);
        $this->assertSame(1, $figures['ranking']['keywords']);
        // A whole number comes back from the JSON column as an int, so the
        // comparison is numeric rather than by type.
        $this->assertEqualsWithDelta(4.0, $figures['ranking']['average_rank'], 0.01);
        $this->assertSame(1, $figures['reviews']['new']);
        $this->assertSame(1, $figures['reviews']['unanswered']);
    }

    public function test_the_prompt_carries_the_periods_figures(): void
    {
        MeoScore::factory()->forLocation($this->location)->on('2026-09-08', 71.5)->create();
        $this->fakeModel();

        $this->runWeekly();

        Http::assertSent(function ($request) {
            $prompt = $request->data()['messages'][0]['content'];

            $this->assertStringContainsString('71.5', $prompt);
            $this->assertStringContainsString('2026-09-02', $prompt);
            $this->assertStringContainsString('週次分析', $prompt);
            $this->assertStringContainsString('直近1週間', $request->data()['system']);

            return true;
        });
    }

    public function test_the_daily_prompt_is_told_not_to_call_a_trend_from_one_day(): void
    {
        $this->fakeModel();

        $this->runDaily();

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('傾向を断定せず', $request->data()['system']);

            return true;
        });
    }

    public function test_the_score_change_over_the_period_is_reported(): void
    {
        MeoScore::factory()->forLocation($this->location)->on('2026-09-01', 60.5)->create();
        MeoScore::factory()->forLocation($this->location)->on('2026-09-08', 71.5)->create();

        $this->fakeModel();

        $this->runWeekly();

        $this->assertEqualsWithDelta(
            11.0,
            Analysis::acrossTenants()->firstOrFail()->content['figures']['score_change'],
            0.01,
        );
    }

    public function test_rerunning_a_period_overwrites_rather_than_adding_a_second_reading(): void
    {
        $this->fakeModel();

        $this->runWeekly();
        $this->runWeekly();

        $this->assertSame(1, Analysis::acrossTenants()->count());
    }

    public function test_the_daily_and_weekly_readings_of_the_same_days_sit_side_by_side(): void
    {
        $this->fakeModel();

        $this->runWeekly();
        $this->runDaily();

        $this->assertSame(2, Analysis::acrossTenants()->count());
    }

    public function test_json_wrapped_in_a_code_fence_is_still_read(): void
    {
        $this->fakeModel("```json\n".json_encode([
            'summary' => '要約です。',
            'highlights' => [],
            'watch' => [],
        ], JSON_UNESCAPED_UNICODE)."\n```");

        $this->runWeekly();

        $this->assertSame('要約です。', Analysis::acrossTenants()->firstOrFail()->summary());
    }

    public function test_an_answer_without_a_summary_is_not_stored(): void
    {
        $this->fakeModel('これはJSONではありません。');

        try {
            $this->runWeekly();
            $this->fail('An unreadable answer should have been raised for the queue to retry.');
        } catch (AIException $e) {
            // expected
        }

        $this->assertSame(0, Analysis::acrossTenants()->count());
    }

    public function test_a_model_that_declines_stores_nothing(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-4-5',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'other'],
        ])]);

        $this->runWeekly();

        $this->assertSame(0, Analysis::acrossTenants()->count());
    }

    public function test_the_jobs_run_on_the_ai_queue(): void
    {
        $this->assertSame('ai', (new GenerateWeeklyAnalysisJob($this->location))->queue);
        $this->assertSame('ai', (new GenerateDailyAnalysisJob($this->location))->queue);
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        $this->fakeModel();

        $this->runWeekly();

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_the_daily_sweep_scores_every_plan_but_only_analyses_the_ones_that_pay_for_it(): void
    {
        Queue::fake();

        // Weekly-only plan: scored, but no daily analysis.
        Location::factory()->create([
            'organization_id' => $this->organizationOnPlan([
                Feature::AiWeeklyAnalysisEnabled->value => true,
                Feature::AiDailyAnalysisEnabled->value => false,
            ])->id,
        ]);

        $this->artisan('ai:insights daily')->assertSuccessful();

        Queue::assertPushed(CalculateMEOScoreJob::class, 2);
        Queue::assertPushed(GenerateDailyAnalysisJob::class, 1);
    }

    public function test_the_weekly_sweep_does_not_recalculate_the_score(): void
    {
        Queue::fake();

        $this->artisan('ai:insights weekly')->assertSuccessful();

        Queue::assertNotPushed(CalculateMEOScoreJob::class);
        Queue::assertPushed(GenerateWeeklyAnalysisJob::class, 1);
    }

    public function test_the_sweep_skips_suspended_organizations(): void
    {
        Queue::fake();

        $this->organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->artisan('ai:insights daily')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_sweep_queues_no_model_work_when_no_provider_is_configured(): void
    {
        Queue::fake();

        config(['ai.claude.api_key' => null]);

        // The score needs no provider, so it still runs; everything that calls
        // a model would only throw on the worker and land in failed_jobs.
        $this->artisan('ai:insights daily')
            ->expectsOutputToContain('No AI provider is configured')
            ->assertSuccessful();

        Queue::assertPushed(CalculateMEOScoreJob::class, 1);
        Queue::assertNotPushed(GenerateDailyAnalysisJob::class);

        $this->artisan('ai:insights weekly')->assertSuccessful();

        Queue::assertNotPushed(GenerateWeeklyAnalysisJob::class);
    }

    public function test_an_unknown_cadence_is_refused(): void
    {
        Queue::fake();

        $this->artisan('ai:insights hourly')->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_the_sweeps_are_scheduled_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events());

        $daily = $events->first(fn ($event) => str_contains($event->command ?? '', 'ai:insights daily'));
        $weekly = $events->first(fn ($event) => str_contains($event->command ?? '', 'ai:insights weekly'));

        $this->assertSame('0 5 * * *', $daily->expression);
        $this->assertSame('Asia/Tokyo', $daily->timezone);
        $this->assertSame('0 6 * * 1', $weekly->expression);
        $this->assertSame('Asia/Tokyo', $weekly->timezone);
    }

    public function test_a_member_reads_the_analyses_newest_first(): void
    {
        Analysis::factory()->forLocation($this->location)->create(['period_start' => '2026-08-24']);
        Analysis::factory()->forLocation($this->location)->create(['period_start' => '2026-08-31']);

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/analyses")
            ->assertOk()
            ->assertJsonCount(2, 'analyses')
            ->assertJsonPath('analyses.0.period_start', '2026-08-31')
            ->assertJsonPath('analyses.0.type', 'weekly');
    }

    public function test_the_analyses_can_be_filtered_by_type(): void
    {
        Analysis::factory()->forLocation($this->location)->type(AnalysisType::Daily)
            ->create(['period_start' => '2026-09-08', 'period_end' => '2026-09-08']);
        Analysis::factory()->forLocation($this->location)->create(['period_start' => '2026-08-31']);

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/analyses?type=daily")
            ->assertOk()
            ->assertJsonCount(1, 'analyses')
            ->assertJsonPath('analyses.0.type', 'daily');
    }

    public function test_analyses_of_another_organization_are_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$foreign->id}/analyses")
            ->assertNotFound();
    }
}
