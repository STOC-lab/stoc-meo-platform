<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\ContentCampaign;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentCampaign>
 */
class ContentCampaignFactory extends Factory
{
    protected $model = ContentCampaign::class;

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
            'name' => fake()->words(3, true).'キャンペーン',
            'theme' => fake()->realText(60),
            'source_image_path' => 'https://cdn.example.com/'.fake()->uuid().'.jpg',
            'campaign_type' => CampaignType::Manual,
            'scheduled_at' => null,
            'status' => CampaignStatus::Draft,
            'created_by_user_id' => null,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn () => ['campaign_type' => CampaignType::Recurring]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => CampaignStatus::Active]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => CampaignStatus::Cancelled]);
    }

    public function withoutImage(): static
    {
        return $this->state(fn () => ['source_image_path' => null]);
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
