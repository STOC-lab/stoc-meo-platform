<?php

namespace Database\Factories;

use App\Enums\GbpPostStatus;
use App\Models\GbpPost;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GbpPost>
 */
class GbpPostFactory extends Factory
{
    protected $model = GbpPost::class;

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
            'content' => fake()->realText(120),
            'media_url' => null,
            'cta_type' => null,
            'cta_url' => null,
            'status' => GbpPostStatus::Draft,
            'published_at' => null,
            'gbp_post_id' => null,
            'failure_reason' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => GbpPostStatus::Published,
            'published_at' => now(),
            'gbp_post_id' => 'accounts/1/locations/2/localPosts/'.fake()->numerify('##########'),
        ]);
    }

    public function failed(string $reason = 'Googleへの投稿に失敗しました。'): static
    {
        return $this->state(fn () => [
            'status' => GbpPostStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
