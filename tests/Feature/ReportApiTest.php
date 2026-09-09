<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');

        $this->organization = $this->organizationOnPlan([Feature::PdfReportEnabled->value => true]);

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
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

    protected function member(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();
        ($organization ?? $this->organization)->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = '', ?Location $location = null): string
    {
        $location ??= $this->location;

        return "/api/v1/locations/{$location->id}/reports".$path;
    }

    /**
     * A finished report with its PDF actually on the disk.
     */
    protected function generatedReport(string $period = '2026-08', ?Organization $organization = null): Report
    {
        $organization ??= $this->organization;

        $report = Report::factory()->forPeriod($period)->completed()->create([
            'organization_id' => $organization->id,
        ]);

        Storage::disk('reports')->put($report->path, '%PDF-1.7 fake');

        return $report;
    }

    public function test_a_member_lists_the_reports_newest_first(): void
    {
        $this->generatedReport('2026-07');
        $this->generatedReport('2026-08');

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'reports')
            ->assertJsonPath('reports.0.period', '2026-08')
            ->assertJsonPath('reports.0.period_label', '2026年8月')
            ->assertJsonPath('reports.0.status', 'completed')
            ->assertJsonPath('reports.0.downloadable', true)
            ->assertJsonPath('reports.1.period', '2026-07');
    }

    public function test_a_report_that_is_still_being_built_is_listed_as_not_downloadable(): void
    {
        Report::factory()->forPeriod('2026-08')->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('reports.0.status', 'pending')
            ->assertJsonPath('reports.0.downloadable', false);
    }

    public function test_a_failed_report_says_why(): void
    {
        Report::factory()->forPeriod('2026-08')
            ->failed('レンダリングに失敗しました。')
            ->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('reports.0.status', 'failed')
            ->assertJsonPath('reports.0.failure_reason', 'レンダリングに失敗しました。');
    }

    public function test_another_organizations_reports_are_not_listed(): void
    {
        $this->generatedReport('2026-08');
        $this->generatedReport('2026-08', Organization::factory()->create());

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'reports');
    }

    public function test_a_member_downloads_the_pdf(): void
    {
        $report = $this->generatedReport();

        $response = $this->actingAs($this->member('viewer'))
            ->get($this->url("/{$report->id}"))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString(
            'meo-report-2026-08.pdf',
            $response->headers->get('content-disposition'),
        );
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_a_report_whose_file_is_missing_is_not_downloadable(): void
    {
        $report = $this->generatedReport();

        Storage::disk('reports')->delete($report->path);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$report->id}"))
            ->assertNotFound();
    }

    public function test_a_report_that_has_not_been_built_yet_cannot_be_downloaded(): void
    {
        $report = Report::factory()->forPeriod('2026-08')->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$report->id}"))
            ->assertNotFound()
            ->assertJsonPath('report.status', 'pending');
    }

    public function test_another_organizations_report_is_not_found(): void
    {
        $report = $this->generatedReport('2026-08', Organization::factory()->create());

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$report->id}"))
            ->assertNotFound();
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

    public function test_a_plan_without_pdf_reports_cannot_reach_them(): void
    {
        $organization = $this->organizationOnPlan([Feature::PdfReportEnabled->value => false]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($this->member('org_admin', $organization))
            ->getJson($this->url('', $location))
            ->assertForbidden()
            ->assertJsonPath('feature', 'pdf_report.enabled')
            ->assertJsonPath('upgrade.required', true);
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
