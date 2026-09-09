<?php

namespace Database\Factories;

use App\Enums\InstagramTokenStatus;
use App\Models\InstagramAccount;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstagramAccount>
 */
class InstagramAccountFactory extends Factory
{
    protected $model = InstagramAccount::class;

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
            'ig_user_id' => (string) fake()->unique()->numerify('#################'),
            'username' => fake()->unique()->userName(),
            'access_token_encrypted' => 'IGQ'.fake()->lexify('????????????????'),
            'token_expires_at' => now()->addDays(60),
            'token_status' => InstagramTokenStatus::Active,
            'last_published_at' => null,
        ];
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_status' => InstagramTokenStatus::Expired]);
    }
}
