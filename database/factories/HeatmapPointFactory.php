<?php

namespace Database\Factories;

use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeatmapPoint>
 */
class HeatmapPointFactory extends Factory
{
    protected $model = HeatmapPoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $run = HeatmapRun::factory();

        return [
            'heatmap_run_id' => $run,
            'organization_id' => fn (array $attributes) => HeatmapRun::acrossTenants()
                ->whereKey($attributes['heatmap_run_id'])
                ->value('organization_id'),
            'lat' => fake()->latitude(35.5, 35.8),
            'lng' => fake()->longitude(139.5, 139.9),
            'rank' => fake()->numberBetween(1, 20),
            'row' => 0,
            'col' => 0,
        ];
    }

    /**
     * The store front did not appear in the results seen from this point.
     */
    public function unranked(): static
    {
        return $this->state(fn () => ['rank' => null]);
    }

    public function at(int $row, int $col): static
    {
        return $this->state(fn () => ['row' => $row, 'col' => $col]);
    }

    public function forRun(HeatmapRun $run): static
    {
        return $this->state(fn () => [
            'heatmap_run_id' => $run->getKey(),
            'organization_id' => $run->organization_id,
        ]);
    }
}
