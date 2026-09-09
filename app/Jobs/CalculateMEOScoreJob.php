<?php

namespace App\Jobs;

use App\Models\Location;
use App\Models\MeoScore;
use App\Services\MEO\MEOScoreCalculator;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scores one store front for one day.
 *
 * The calculation reads what is already stored rather than calling anything
 * out, so it is cheap and can be rerun: a day is scored once and rescoring
 * overwrites it. It runs on the default queue for that reason — there is no
 * outbound call to hold a worker.
 */
class CalculateMEOScoreJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public Location $location,
        public ?string $forDate = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MEOScoreCalculator $calculator, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->location->organization_id, function () use ($calculator) {
            $at = $this->forDate === null
                ? CarbonImmutable::now()
                : CarbonImmutable::parse($this->forDate);

            $result = $calculator->calculate($this->location, $at);

            MeoScore::acrossTenants()->updateOrCreate(
                [
                    'location_id' => $this->location->getKey(),
                    'calculated_at' => $at->startOfDay(),
                ],
                [
                    'organization_id' => $this->location->organization_id,
                    'score' => $result['score'],
                    'breakdown' => $result['breakdown'],
                ],
            );
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'meo-score',
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
