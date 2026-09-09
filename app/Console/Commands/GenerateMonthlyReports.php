<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Enums\ReportStatus;
use App\Jobs\MonthlyReportJob;
use App\Models\Organization;
use App\Models\Report;
use App\Services\FeatureResolver;
use App\Services\Reports\ReportPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Queues last month's PDF for every organization whose plan includes one.
 *
 * It runs on the first of the month, so the period reported on is the month
 * that has just closed; --period overrides that for a rebuild.
 */
class GenerateMonthlyReports extends Command
{
    protected $signature = 'reports:generate-monthly {--period= : The month to report on, as YYYY-MM. Defaults to last month.}';

    protected $description = 'Queue the monthly PDF report for every organization on a plan that includes one';

    public function handle(FeatureResolver $features, ReportPdfRenderer $renderer): int
    {
        $period = $this->period();

        if ($period === null) {
            $this->error('The --period option must be a month, as YYYY-MM.');

            return self::FAILURE;
        }

        // A report with no Japanese in it is not worth much, and the cause is
        // an unconfigured font rather than anything in the data.
        if (! $renderer->hasEmbeddableFont()) {
            $this->warn('No report font is configured, so Japanese text will not render. See reports.font in the configuration.');
        }

        $queued = 0;
        $skipped = 0;

        Organization::query()
            ->withoutGlobalScopes()
            ->orderBy('id')
            ->chunkById(200, function ($organizations) use ($features, $period, &$queued, &$skipped) {
                foreach ($organizations as $organization) {
                    if (! $this->shouldReport($organization, $features)) {
                        $skipped++;

                        continue;
                    }

                    MonthlyReportJob::dispatch($this->reportFor($organization, $period));
                    $queued++;
                }
            });

        $this->info("Queued {$queued} report(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * A month is reported on once, so a rebuild reuses the row and its file
     * rather than leaving the old one beside the new.
     */
    protected function reportFor(Organization $organization, CarbonImmutable $period): Report
    {
        $report = Report::acrossTenants()->firstOrNew([
            'organization_id' => $organization->getKey(),
            'period_start' => $period->toDateString(),
        ]);

        $report->forceFill([
            'status' => ReportStatus::Pending,
            'failure_reason' => null,
        ])->save();

        return $report;
    }

    protected function shouldReport(Organization $organization, FeatureResolver $features): bool
    {
        return $organization->isActive()
            && $features->allows(Feature::PdfReportEnabled, $organization);
    }

    /**
     * The month to report on, or null when the option was given but unusable.
     */
    protected function period(): ?CarbonImmutable
    {
        $option = $this->option('period');

        if ($option === null) {
            return CarbonImmutable::now()->subMonth()->startOfMonth();
        }

        if (! is_string($option) || preg_match('/^\d{4}-\d{2}$/', $option) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $option.'-01')->startOfMonth();
    }
}
