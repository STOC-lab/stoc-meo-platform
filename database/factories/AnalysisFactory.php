<?php

namespace Database\Factories;

use App\Enums\AnalysisType;
use App\Models\Analysis;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $location = Location::factory();

        return [
            'location_id' => $location,
            'organization_id' => fn (array $attributes) => Location::acrossTenants()
                ->whereKey($attributes['location_id'])
                ->value('organization_id'),
            'type' => AnalysisType::Weekly,
            'content' => [
                'summary' => '順位は横ばいですが、口コミの返信率が改善しました。',
                'highlights' => ['口コミ返信率が20%上昇'],
                'watch' => ['「渋谷 カフェ」の順位が2位下降'],
            ],
            'period_start' => now()->subWeek()->startOfWeek()->toDateString(),
            'period_end' => now()->subWeek()->endOfWeek()->toDateString(),
            'model' => 'claude-haiku-4-5',
        ];
    }

    public function type(AnalysisType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
