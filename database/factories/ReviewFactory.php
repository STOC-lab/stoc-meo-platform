<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

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
            'google_review_id' => fake()->unique()->uuid(),
            'author_name' => fake()->name(),
            'author_photo_url' => fake()->imageUrl(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->realText(80),
            'reply' => null,
            'replied_at' => null,
            'reviewed_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ];
    }

    public function answered(string $reply = 'ご来店ありがとうございました。'): static
    {
        return $this->state(fn () => [
            'reply' => $reply,
            'replied_at' => now(),
        ]);
    }

    public function rated(int $rating): static
    {
        return $this->state(fn () => ['rating' => $rating]);
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
