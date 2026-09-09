<?php

namespace App\Console\Commands;

use App\Enums\AnalysisType;
use App\Enums\Feature;
use App\Jobs\CalculateMEOScoreJob;
use App\Jobs\GenerateDailyAnalysisJob;
use App\Jobs\GenerateImprovementProposalsJob;
use App\Jobs\GenerateWeeklyAnalysisJob;
use App\Models\Location;
use App\Models\Organization;
use App\Services\FeatureResolver;
use Illuminate\Console\Command;

/**
 * Queues the AI work of one cadence.
 *
 * The MEO score is calculated for every store front regardless of plan: it is
 * what the rest is written from, it costs nothing but a few queries, and a
 * shop that later upgrades then has history rather than starting blank. Only
 * the parts that call a model are gated on the plan.
 */
class RunAiInsights extends Command
{
    protected $signature = 'ai:insights {cadence : daily or weekly}';

    protected $description = 'Queue the MEO scores, analyses and improvement proposals of one cadence';

    public function handle(FeatureResolver $features): int
    {
        $cadence = (string) $this->argument('cadence');

        if (! in_array($cadence, ['daily', 'weekly'], true)) {
            $this->error('The cadence must be daily or weekly.');

            return self::FAILURE;
        }

        $queued = ['scores' => 0, 'analyses' => 0, 'proposals' => 0];

        Location::acrossTenants()
            ->with('organization')
            ->orderBy('id')
            ->chunkById(200, function ($locations) use ($cadence, $features, &$queued) {
                foreach ($locations as $location) {
                    $organization = $location->organization;

                    if ($organization === null || ! $organization->isActive()) {
                        continue;
                    }

                    if ($cadence === 'daily') {
                        // The score is what everything else reads, so it is
                        // calculated for every store front on every plan.
                        CalculateMEOScoreJob::dispatch($location);
                        $queued['scores']++;
                    }

                    $queued = $this->queueAiWork($cadence, $location, $organization, $features, $queued);
                }
            });

        $this->info(sprintf(
            'Queued %d score(s), %d analysis/analyses and %d proposal run(s).',
            $queued['scores'],
            $queued['analyses'],
            $queued['proposals'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $queued
     * @return array<string, int>
     */
    protected function queueAiWork(
        string $cadence,
        Location $location,
        Organization $organization,
        FeatureResolver $features,
        array $queued,
    ): array {
        if ($cadence === 'daily') {
            if ($features->allows(AnalysisType::Daily->feature(), $organization)) {
                GenerateDailyAnalysisJob::dispatch($location);
                $queued['analyses']++;
            }

            return $queued;
        }

        if ($features->allows(AnalysisType::Weekly->feature(), $organization)) {
            GenerateWeeklyAnalysisJob::dispatch($location);
            $queued['analyses']++;
        }

        if ($features->allows(Feature::AiImprovementProposalsEnabled, $organization)) {
            GenerateImprovementProposalsJob::dispatch($location);
            $queued['proposals']++;
        }

        return $queued;
    }
}
