<?php

namespace Database\Factories;

use App\Models\Keyword;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keyword>
 */
class KeywordFactory extends Factory
{
    protected $model = Keyword::class;

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
            'keyword' => fake()->city().' '.fake()->randomElement(['カフェ', '美容室', 'ラーメン', '歯科']),
            'is_active' => true,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
