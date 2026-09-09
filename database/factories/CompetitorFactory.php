<?php

namespace Database\Factories;

use App\Models\Competitor;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competitor>
 */
class CompetitorFactory extends Factory
{
    protected $model = Competitor::class;

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
            'name' => fake()->company().'店',
            'gbp_place_id' => 'ChIJ'.fake()->unique()->bothify('###??####??##'),
        ];
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
