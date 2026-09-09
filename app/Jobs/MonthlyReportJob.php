<?php

namespace App\Jobs;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Services\Reports\MonthlyReportBuilder;
use App\Services\Reports\ReportPdfRenderer;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Builds one organization's PDF for one month and writes it to the reports
 * disk at {organization}/{YYYY-MM}.pdf.
 *
 * Rendering a PDF holds a worker for as long as the document is large, so it
 * runs on its own queue rather than behind the fast jobs. A month is reported
 * on once: re-running overwrites the file and the row rather than adding a
 * second of either, so a retry is safe and a correction is just another run.
 */
class MonthlyReportJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'reports';

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public Report $report)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        MonthlyReportBuilder $builder,
        ReportPdfRenderer $renderer,
        FilesystemFactory $filesystem,
        Tenancy $tenancy,
    ): void {
        $tenancy->forOrganization($this->report->organization_id, function () use ($builder, $renderer, $filesystem) {
            $report = $this->report;
            $organization = $report->organization;

            if ($organization === null) {
                $report->markFailed('組織が見つかりません。');

                return;
            }

            $report->forceFill(['status' => ReportStatus::Generating])->save();

            $pdf = $renderer->render($builder->build(
                $organization,
                CarbonImmutable::instance($report->period_start)->startOfMonth(),
            ));

            $path = Report::pathFor($organization->getKey(), $report->period_start);

            $filesystem->disk(config('reports.disk'))->put($path, $pdf);

            $report->markGenerated($path, strlen($pdf));
        });
    }

    /**
     * Once the retries are spent the row is closed off, so the list shows why
     * the report never arrived rather than leaving it forever in progress.
     */
    public function failed(?Throwable $exception): void
    {
        app(Tenancy::class)->forOrganization($this->report->organization_id, function () use ($exception) {
            $report = $this->report->fresh();

            if ($report === null || $report->status->isFinished()) {
                return;
            }

            $report->markFailed($exception?->getMessage() ?? 'レポートの作成に失敗しました。');
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'reports',
            'organization:'.$this->report->organization_id,
            'report:'.$this->report->getKey(),
        ];
    }
}
