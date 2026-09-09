<?php

namespace Database\Factories;

use App\Enums\GbpTokenStatus;
use App\Models\GbpAccount;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GbpAccount>
 */
class GbpAccountFactory extends Factory
{
    protected $model = GbpAccount::class;

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
            'google_account_id' => (string) fake()->unique()->numerify('###################'),
            'google_email' => fake()->unique()->safeEmail(),
            'gbp_account_name' => 'accounts/'.fake()->numerify('##########'),
            'access_token_encrypted' => 'ya29.'.fake()->lexify('????????????????'),
            'refresh_token_encrypted' => '1//'.fake()->lexify('????????????????'),
            'token_expires_at' => now()->addHour(),
            'token_status' => GbpTokenStatus::Active,
            'last_synced_at' => null,
        ];
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }

    /**
     * An access token that has already run out, so a call has to renew it.
     */
    public function withExpiredAccessToken(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subMinutes(5)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_status' => GbpTokenStatus::Expired]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['token_status' => GbpTokenStatus::Revoked]);
    }

    public function withoutRefreshToken(): static
    {
        return $this->state(fn () => ['refresh_token_encrypted' => null]);
    }
}
