<?php

namespace Database\Factories;

use App\Models\GbpPerformanceMetric;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GbpPerformanceMetric>
 */
class GbpPerformanceMetricFactory extends Factory
{
    protected $model = GbpPerformanceMetric::class;

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
            'metric' => 'CALL_CLICKS',
            'date' => now()->subDay()->toDateString(),
            'value' => fake()->numberBetween(0, 500),
        ];
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }

    public function on(string $date, string $metric, int $value): static
    {
        return $this->state(fn () => [
            'date' => $date,
            'metric' => $metric,
            'value' => $value,
        ]);
    }
}
