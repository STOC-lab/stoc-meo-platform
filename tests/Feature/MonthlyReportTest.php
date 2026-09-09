<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\Feature;
use App\Enums\HeatmapGridSize;
use App\Enums\ReportStatus;
use App\Jobs\MonthlyReportJob;
use App\Models\Alert;
use App\Models\Competitor;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankingResult;
use App\Models\Report;
use App\Services\Reports\MonthlyReportBuilder;
use App\Services\Reports\ReportPdfRenderer;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    protected CarbonImmutable $period;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');

        $this->period = CarbonImmutable::create(2026, 8, 1)->startOfMonth();
    }

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function organizationOnPlan(array $features = [], array $attributes = []): Organization
    {
        return Organization::factory()
            ->onPlan(Plan::factory()->withFeatures($features + [
                Feature::PdfReportEnabled->value => true,
            ])->create())
            ->create($attributes);
    }

    protected function reportFor(Organization $organization): Report
    {
        return Report::factory()->create([
            'organization_id' => $organization->id,
            'period_start' => $this->period->toDateString(),
        ]);
    }

    protected function runJob(Report $report): void
    {
        (new MonthlyReportJob($report))->handle(
            app(MonthlyReportBuilder::class),
            app(ReportPdfRenderer::class),
            app(FilesystemFactory::class),
            app(Tenancy::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(Organization $organization): array
    {
        return app(MonthlyReportBuilder::class)->build($organization, $this->period);
    }

    public function test_the_summary_reports_the_best_worst_and_average_rank_of_each_keyword(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id, 'name' => '渋谷店']);
        $keyword = Keyword::factory()->forLocation($location)->create(['keyword' => '渋谷 カフェ']);

        foreach ([3, 9, 6] as $index => $rank) {
            RankingResult::factory()->forKeyword($keyword)->create([
                'rank' => $rank,
                'checked_at' => $this->period->addDays($index),
            ]);
        }

        $summary = $this->build($organization)['locations'][0]['rankings'][0];

        $this->assertSame('渋谷 カフェ', $summary['keyword']);
        $this->assertSame(3, $summary['checks']);
        $this->assertSame(3, $summary['best']);
        $this->assertSame(9, $summary['worst']);
        $this->assertSame(6.0, $summary['average']);
        $this->assertSame(0, $summary['missing']);
    }

    public function test_checks_that_found_nothing_are_counted_but_not_averaged_in(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 4,
            'checked_at' => $this->period->addDay(),
        ]);
        RankingResult::factory()->forKeyword($keyword)->unranked()->create([
            'checked_at' => $this->period->addDays(2),
        ]);

        $summary = $this->build($organization)['locations'][0]['rankings'][0];

        $this->assertSame(2, $summary['checks']);
        $this->assertSame(1, $summary['missing']);
        $this->assertSame(4.0, $summary['average']);
    }

    public function test_checks_outside_the_month_are_left_out(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 2,
            'checked_at' => $this->period->addDay(),
        ]);
        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 40,
            'checked_at' => $this->period->subMonth(),
        ]);

        $summary = $this->build($organization)['locations'][0]['rankings'][0];

        $this->assertSame(1, $summary['checks']);
        $this->assertSame(2, $summary['worst']);
    }

    public function test_the_summary_reports_the_months_heatmap_runs_and_their_coverage(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        $run = HeatmapRun::factory()->forKeyword($keyword)
            ->gridSize(HeatmapGridSize::Grid5x5)
            ->completed()
            ->create(['created_at' => $this->period->addDays(3)]);

        HeatmapPoint::factory()->forRun($run)->at(0, 0)->create(['rank' => 2]);
        HeatmapPoint::factory()->forRun($run)->at(0, 1)->create(['rank' => 8]);
        HeatmapPoint::factory()->forRun($run)->at(0, 2)->unranked()->create();

        HeatmapRun::factory()->forKeyword($keyword)->failed()
            ->create(['created_at' => $this->period->addDays(4)]);

        $heatmaps = $this->build($organization)['locations'][0]['heatmaps'];

        $this->assertSame(2, $heatmaps['total']);
        $this->assertSame(1, $heatmaps['completed']);
        $this->assertSame(1, $heatmaps['failed']);
        $this->assertSame(3, $heatmaps['runs'][0]['total_points']);
        $this->assertSame(2, $heatmaps['runs'][0]['ranked_points']);
        $this->assertSame(2, $heatmaps['runs'][0]['best']);
        $this->assertSame(5.0, $heatmaps['runs'][0]['average']);
    }

    public function test_the_summary_lists_the_competitors_watched_against_the_stores_own_average(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 5,
            'checked_at' => $this->period->addDay(),
        ]);

        Competitor::factory()->forLocation($location)->create(['name' => '向かいのカフェ']);

        $competitors = $this->build($organization)['locations'][0]['competitors'];

        $this->assertSame(5.0, $competitors['own_average']);
        $this->assertCount(1, $competitors['tracked']);
        $this->assertSame('向かいのカフェ', $competitors['tracked'][0]['name']);
    }

    public function test_the_summary_lists_the_months_rank_drop_alerts(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        Alert::factory()->forKeyword($keyword)->create([
            'type' => AlertType::RankDrop,
            'payload' => ['keyword' => '渋谷 カフェ', 'previous_rank' => 3, 'current_rank' => 14, 'drop' => 11],
            'created_at' => $this->period->addDays(6),
        ]);
        Alert::factory()->forKeyword($keyword)->create([
            'created_at' => $this->period->subMonth(),
        ]);

        $alerts = $this->build($organization)['locations'][0]['alerts'];

        $this->assertCount(1, $alerts);
        $this->assertSame('渋谷 カフェ', $alerts[0]['keyword']);
        $this->assertSame(11, $alerts[0]['drop']);
    }

    public function test_the_summary_covers_every_store_front_of_the_organization(): void
    {
        $organization = $this->organizationOnPlan();
        Location::factory()->create(['organization_id' => $organization->id, 'name' => '新宿店']);
        Location::factory()->create(['organization_id' => $organization->id, 'name' => '渋谷店']);
        Location::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $data = $this->build($organization);

        $this->assertSame(2, $data['totals']['locations']);
        $this->assertSame(['新宿店', '渋谷店'], array_column($data['locations'], 'name'));
    }

    public function test_the_job_writes_the_pdf_where_the_design_says(): void
    {
        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 5,
            'checked_at' => $this->period->addDay(),
        ]);

        $report = $this->reportFor($organization);

        $this->runJob($report);

        $path = "{$organization->id}/2026-08.pdf";

        Storage::disk('reports')->assertExists($path);

        $report->refresh();

        $this->assertSame(ReportStatus::Completed, $report->status);
        $this->assertSame($path, $report->path);
        $this->assertGreaterThan(0, $report->size);
        $this->assertNotNull($report->generated_at);
        $this->assertStringStartsWith('%PDF-', Storage::disk('reports')->get($path));
    }

    public function test_rebuilding_a_month_replaces_the_file_rather_than_adding_another(): void
    {
        $organization = $this->organizationOnPlan();
        Location::factory()->create(['organization_id' => $organization->id]);

        $report = $this->reportFor($organization);

        $this->runJob($report);
        $first = Storage::disk('reports')->size($report->refresh()->path);

        $report->forceFill(['status' => ReportStatus::Pending])->save();
        $this->runJob($report->refresh());

        $this->assertCount(1, Storage::disk('reports')->allFiles("{$organization->id}"));
        $this->assertGreaterThan(0, $first);
    }

    public function test_the_job_runs_on_the_reports_queue_and_retries_with_a_backoff(): void
    {
        $job = new MonthlyReportJob($this->reportFor($this->organizationOnPlan()));

        $this->assertSame('reports', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        $organization = $this->organizationOnPlan();
        Location::factory()->create(['organization_id' => $organization->id]);

        $this->runJob($this->reportFor($organization));

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_an_exhausted_report_is_closed_off_rather_than_left_in_progress(): void
    {
        $report = $this->reportFor($this->organizationOnPlan());

        (new MonthlyReportJob($report))->failed(new \RuntimeException('レンダリングに失敗しました。'));

        $this->assertSame(ReportStatus::Failed, $report->refresh()->status);
        $this->assertSame('レンダリングに失敗しました。', $report->failure_reason);
    }

    public function test_the_command_queues_last_month_for_every_organization_on_a_plan_with_reports(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 3, 0));

        $included = $this->organizationOnPlan();
        $this->organizationOnPlan([Feature::PdfReportEnabled->value => false]);

        $this->artisan('reports:generate-monthly')->assertSuccessful();

        Queue::assertPushed(MonthlyReportJob::class, 1);

        $this->assertDatabaseHas('reports', [
            'organization_id' => $included->id,
            'period_start' => '2026-08-01',
            'status' => ReportStatus::Pending->value,
        ]);
    }

    public function test_the_command_skips_suspended_organizations(): void
    {
        Queue::fake();

        $this->organizationOnPlan([], ['status' => Organization::STATUS_SUSPENDED]);

        $this->artisan('reports:generate-monthly')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_command_reports_on_the_month_it_is_given(): void
    {
        Queue::fake();

        $organization = $this->organizationOnPlan();

        $this->artisan('reports:generate-monthly', ['--period' => '2026-05'])->assertSuccessful();

        $this->assertDatabaseHas('reports', [
            'organization_id' => $organization->id,
            'period_start' => '2026-05-01',
        ]);
    }

    public function test_the_command_refuses_a_period_that_is_not_a_month(): void
    {
        Queue::fake();

        $this->organizationOnPlan();

        $this->artisan('reports:generate-monthly', ['--period' => 'august'])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_a_month_is_reported_on_once_however_often_the_sweep_runs(): void
    {
        Queue::fake();

        $organization = $this->organizationOnPlan();

        $this->artisan('reports:generate-monthly', ['--period' => '2026-08'])->assertSuccessful();
        $this->artisan('reports:generate-monthly', ['--period' => '2026-08'])->assertSuccessful();

        $this->assertSame(1, Report::acrossTenants()
            ->where('organization_id', $organization->id)
            ->count());
    }

    public function test_the_sweep_is_scheduled_for_the_first_of_the_month_at_three_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'reports:generate-monthly'));

        $this->assertCount(1, $events, 'The monthly report sweep should be scheduled exactly once.');

        $event = $events->first();

        $this->assertSame('0 3 1 * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }

    public function test_the_renderer_falls_back_when_no_japanese_font_is_configured(): void
    {
        $renderer = new ReportPdfRenderer(['family' => 'Noto Sans JP', 'path' => null]);

        $this->assertFalse($renderer->hasEmbeddableFont());
        $this->assertSame('sans-serif', $renderer->font()['family']);
    }

    public function test_the_renderer_embeds_the_configured_font_when_the_file_is_there(): void
    {
        $path = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');

        $renderer = new ReportPdfRenderer(['family' => 'Report Sans', 'path' => $path]);

        $this->assertTrue($renderer->hasEmbeddableFont());
        $this->assertSame('Report Sans', $renderer->font()['family']);
        $this->assertSame(dirname($path), $renderer->font()['directory']);
    }

    public function test_a_configured_font_that_is_not_on_the_machine_is_not_declared(): void
    {
        $renderer = new ReportPdfRenderer(['family' => 'Noto Sans JP', 'path' => '/no/such/font.ttf']);

        $this->assertFalse($renderer->hasEmbeddableFont());
    }

    public function test_a_font_collection_is_declined_rather_than_left_to_fail_mid_render(): void
    {
        // Dompdf cannot parse a .ttc, which is what fonts-noto-cjk installs;
        // declaring one would throw part-way through the render instead of
        // simply coming out in the fallback face.
        $renderer = new ReportPdfRenderer([
            'family' => 'Noto Sans CJK',
            'path' => '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        ]);

        $this->assertFalse($renderer->hasEmbeddableFont());
    }

    public function test_a_report_set_in_a_real_font_embeds_it(): void
    {
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');

        $organization = $this->organizationOnPlan();
        Location::factory()->create(['organization_id' => $organization->id, 'name' => 'Shibuya']);

        config(['reports.font' => ['family' => 'Report Sans', 'path' => $font]]);

        $report = $this->reportFor($organization);

        (new MonthlyReportJob($report))->handle(
            app(MonthlyReportBuilder::class),
            new ReportPdfRenderer(['family' => 'Report Sans', 'path' => $font]),
            app(FilesystemFactory::class),
            app(Tenancy::class),
        );

        $pdf = Storage::disk('reports')->get($report->refresh()->path);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);
    }
}
