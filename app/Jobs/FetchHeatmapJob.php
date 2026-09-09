<?php

namespace App\Jobs;

use App\Exceptions\QuotaExceededException;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Services\Heatmap\GridGenerator;
use App\Services\Heatmap\GridPoint;
use App\Services\Ranking\RankProviderRouter;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Fills in one heatmap: the run's keyword is checked from all 25 or 49 points
 * of its grid and the answers are written in one go.
 *
 * A grid is many outbound calls rather than one, so the job runs on its own
 * queue with a timeout that allows for every point, and the whole grid is
 * retried rather than the points salvaged individually — a half-drawn map is
 * worse than a late one. The points are therefore only written once the last
 * one has answered, and a retry starts from a clean run.
 *
 * The organization's monthly allowance is charged when the run is claimed, so
 * the retries below cost it nothing extra.
 */
class FetchHeatmapJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'heatmap';

    public int $tries = 3;

    /**
     * A 7x7 grid is 49 live searches; the timeout has to allow for all of them
     * rather than for one.
     */
    public int $timeout = 1800;

    public function __construct(public HeatmapRun $run)
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
        RankProviderRouter $providers,
        GridGenerator $grid,
        UsageTracker $usage,
        Tenancy $tenancy,
    ): void {
        $tenancy->forOrganization($this->run->organization_id, function () use ($providers, $grid, $usage) {
            $run = $this->run;

            // A run that has already been answered has nothing left to do;
            // this is what a duplicate dispatch lands on.
            if ($run->status->isFinished()) {
                return;
            }

            if ($run->claim() && ! $this->chargeAllowance($run, $usage)) {
                return;
            }

            $centre = $run->location->coordinate();

            if ($centre === null) {
                $this->giveUp($run, 'この店舗には緯度・経度が登録されていません。');

                return;
            }

            $keyword = $run->keyword;
            $rows = [];

            foreach ($grid->generate($centre, $run->grid_size) as $point) {
                $rows[] = $this->row($run, $point, $providers->fetch($keyword, $point->coordinate)->rank);
            }

            DB::transaction(function () use ($run, $rows) {
                // A retry may have left points behind from the attempt before
                // it, and the grid is rewritten whole.
                $run->points()->delete();
                HeatmapPoint::insert($rows);
                $run->markCompleted();
            });
        });
    }

    /**
     * Charge the run against the plan's monthly allowance for its grid,
     * answering whether there was room. Running out is the organization's
     * answer rather than a fault, so the run is marked failed and not retried.
     */
    protected function chargeAllowance(HeatmapRun $run, UsageTracker $usage): bool
    {
        try {
            $usage->consume($run->grid_size->feature(), 1, $run->organization);
        } catch (QuotaExceededException $e) {
            $this->giveUp($run, 'ご利用中のプランの今月の上限に達しました。');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(HeatmapRun $run, GridPoint $point, ?int $rank): array
    {
        return [
            'heatmap_run_id' => $run->getKey(),
            'organization_id' => $run->organization_id,
            'lat' => $point->coordinate->latitude,
            'lng' => $point->coordinate->longitude,
            'rank' => $rank,
            'row' => $point->row,
            'col' => $point->col,
            'created_at' => now(),
        ];
    }

    /**
     * Stop for a reason retrying cannot fix.
     */
    protected function giveUp(HeatmapRun $run, string $reason): void
    {
        $run->markFailed($reason);

        $this->fail(new RuntimeException($reason));
    }

    /**
     * Once the retries are spent the run is closed off, so the history shows
     * why the map never arrived rather than leaving it forever in progress.
     */
    public function failed(?Throwable $exception): void
    {
        app(Tenancy::class)->forOrganization($this->run->organization_id, function () use ($exception) {
            $run = $this->run->fresh();

            if ($run === null || $run->status->isFinished()) {
                return;
            }

            $run->markFailed($exception?->getMessage() ?? 'ヒートマップの取得に失敗しました。');
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'heatmap',
            'organization:'.$this->run->organization_id,
            'heatmap_run:'.$this->run->getKey(),
        ];
    }
}
