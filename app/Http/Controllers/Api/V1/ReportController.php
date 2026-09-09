<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Report;
use App\Support\Tenancy;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The organization's monthly PDF reports, reached through one of its store
 * fronts.
 *
 * A report covers the whole organization rather than a single store front —
 * it is one PDF a month, with a section per store — so the same list answers
 * from whichever store front it is asked through. The store front in the URL
 * is what says which organization is meant, and it is bound before the tenant
 * middleware has run, so every action checks it belongs to the active one.
 */
class ReportController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected FilesystemFactory $filesystem,
    ) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', Report::class);

        $reports = Report::query()
            ->orderByDesc('period_start')
            ->get();

        return response()->json([
            'reports' => $reports->map(fn (Report $report) => $this->present($report))->all(),
        ]);
    }

    /**
     * Hand over the PDF itself.
     */
    public function show(Location $location, Report $report): StreamedResponse|JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorizeReport($report);
        $this->authorize('view', $report);

        $disk = $this->filesystem->disk(config('reports.disk'));

        if (! $report->status->isDownloadable() || $report->path === null || ! $disk->exists($report->path)) {
            return response()->json([
                'message' => 'このレポートはまだダウンロードできません。',
                'report' => $this->present($report),
            ], 404);
        }

        return $disk->download($report->path, $this->filename($report), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Route model binding resolves the store front before the tenant is known,
     * so it can be one from another organization. Answer as though it does not
     * exist rather than confirming it does.
     */
    protected function authorizeLocation(Location $location): void
    {
        abort_unless($location->organization_id === $this->tenancy->id(), 404);
    }

    /**
     * Bindings are substituted before the tenant middleware has run, so the
     * report resolves without the tenant scope and can be another
     * organization's. Answer as though it does not exist rather than
     * confirming it does.
     */
    protected function authorizeReport(Report $report): void
    {
        abort_unless($report->organization_id === $this->tenancy->id(), 404);
    }

    /**
     * What the file is called once it is on someone's machine.
     */
    protected function filename(Report $report): string
    {
        return sprintf('meo-report-%s.pdf', $report->period_start->format('Y-m'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Report $report): array
    {
        return [
            'id' => $report->id,
            'period' => $report->period_start->format('Y-m'),
            'period_label' => $report->periodLabel(),
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'downloadable' => $report->status->isDownloadable(),
            'size' => $report->size,
            'failure_reason' => $report->failure_reason,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
