<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\MeoScore;
use App\Services\MEO\MEOScoreCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeoScore>
 */
class MeoScoreFactory extends Factory
{
    protected $model = MeoScore::class;

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
            'score' => fake()->randomFloat(1, 20, 95),
            'breakdown' => [
                'ranking' => ['measured' => true, 'score' => 60.0, 'weight' => MEOScoreCalculator::WEIGHTS['ranking'], 'detail' => []],
                'heatmap' => ['measured' => true, 'score' => 45.0, 'weight' => MEOScoreCalculator::WEIGHTS['heatmap'], 'detail' => []],
                'reviews' => ['measured' => true, 'score' => 80.0, 'weight' => MEOScoreCalculator::WEIGHTS['reviews'], 'detail' => []],
                'profile' => ['measured' => true, 'score' => 70.0, 'weight' => MEOScoreCalculator::WEIGHTS['profile'], 'detail' => []],
            ],
            'calculated_at' => now()->startOfDay(),
        ];
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }

    public function on(string $date, float $score): static
    {
        return $this->state(fn () => [
            'calculated_at' => $date.' 00:00:00',
            'score' => $score,
        ]);
    }
}
