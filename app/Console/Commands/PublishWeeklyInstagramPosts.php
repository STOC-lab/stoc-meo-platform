<?php

namespace App\Console\Commands;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\Feature;
use App\Jobs\InstagramPublishJob;
use App\Models\ContentCampaignPost;
use App\Services\FeatureResolver;
use Illuminate\Console\Command;

/**
 * Publishes the week's approved Instagram posts from recurring campaigns.
 *
 * Only approved posts are swept. A campaign on a plan without automatic
 * publishing produces drafts that wait for a person, and those simply are not
 * approved yet when this runs — which is the whole difference between the two
 * flows, and why this command needs no branch for it.
 */
class PublishWeeklyInstagramPosts extends Command
{
    protected $signature = 'instagram:publish-weekly';

    protected $description = 'Publish the approved Instagram posts of recurring campaigns';

    public function handle(FeatureResolver $features): int
    {
        $queued = 0;
        $skipped = 0;

        ContentCampaignPost::acrossTenants()
            ->where('channel', CampaignChannel::Instagram)
            ->where('status', CampaignPostStatus::Approved)
            ->whereHas('campaign', function ($query) {
                $query->where('campaign_type', CampaignType::Recurring)
                    ->whereIn('status', [CampaignStatus::Draft, CampaignStatus::Active]);
            })
            ->with('campaign.location', 'organization')
            ->orderBy('id')
            ->chunkById(200, function ($posts) use ($features, &$queued, &$skipped) {
                foreach ($posts as $post) {
                    if (! $this->shouldPublish($post, $features)) {
                        $skipped++;

                        continue;
                    }

                    InstagramPublishJob::dispatch($post);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} Instagram post(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    protected function shouldPublish(ContentCampaignPost $post, FeatureResolver $features): bool
    {
        $organization = $post->organization;

        return $organization !== null
            && $organization->isActive()
            && $features->allows(Feature::InstagramEnabled, $organization)
            && $features->allows(Feature::InstagramPostMonthlyLimit, $organization);
    }
}
