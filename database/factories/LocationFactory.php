<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'brand_id' => null,
            'name' => fake()->company().'店',
            'gbp_location_id' => 'locations/'.fake()->unique()->numerify('##########'),
            'website_url' => fake()->url(),
            'phone' => fake()->numerify('03-####-####'),
            'address' => fake()->address(),
            // Somewhere in greater Tokyo, so a generated heatmap grid lands on
            // plausible ground.
            'latitude' => fake()->latitude(35.5, 35.8),
            'longitude' => fake()->longitude(139.5, 139.9),
        ];
    }

    /**
     * A store front that has not been placed on the map yet.
     */
    public function withoutCoordinates(): static
    {
        return $this->state(fn () => ['latitude' => null, 'longitude' => null]);
    }
}
