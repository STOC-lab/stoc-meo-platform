<?php

namespace Database\Factories;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Models\ContentCampaign;
use App\Models\ContentCampaignPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentCampaignPost>
 */
class ContentCampaignPostFactory extends Factory
{
    protected $model = ContentCampaignPost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $campaign = ContentCampaign::factory();

        return [
            'campaign_id' => $campaign,
            'organization_id' => fn (array $attributes) => ContentCampaign::acrossTenants()
                ->whereKey($attributes['campaign_id'])
                ->value('organization_id'),
            'channel' => CampaignChannel::Instagram,
            'status' => CampaignPostStatus::Pending,
            'ai_prompt' => null,
            'ai_content' => null,
            'ai_hashtags' => null,
            'platform_post_id' => null,
            'published_at' => null,
            'retry_count' => 0,
            'max_retries' => 3,
            'last_error' => null,
            'idempotency_key' => (string) Str::uuid(),
            'approved_by_user_id' => null,
            'approved_at' => null,
        ];
    }

    public function channel(CampaignChannel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel]);
    }

    public function awaitingApproval(string $content = '本日は10時から営業しています。'): static
    {
        return $this->state(fn () => [
            'status' => CampaignPostStatus::AwaitingApproval,
            'ai_content' => $content,
            'ai_hashtags' => ['#カフェ', '#渋谷'],
        ]);
    }

    public function approved(string $content = '本日は10時から営業しています。'): static
    {
        return $this->state(fn () => [
            'status' => CampaignPostStatus::Approved,
            'ai_content' => $content,
            'approved_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => CampaignPostStatus::Published,
            'ai_content' => '公開済みの投稿です。',
            'platform_post_id' => (string) fake()->numerify('##############'),
            'published_at' => now(),
        ]);
    }

    public function forCampaign(ContentCampaign $campaign): static
    {
        return $this->state(fn () => [
            'campaign_id' => $campaign->getKey(),
            'organization_id' => $campaign->organization_id,
        ]);
    }
}
