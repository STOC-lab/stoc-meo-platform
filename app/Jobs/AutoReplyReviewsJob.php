<?php

namespace App\Jobs;

use App\Enums\Feature;
use App\Models\Location;
use App\Models\Review;
use App\Services\FeatureResolver;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues an AI reply for every review of a store front that has not been
 * answered.
 *
 * Dispatched after a review sync, so a review that arrived overnight is
 * answered without anyone opening the application. The generation job is what
 * actually writes and — on a plan with review.auto_reply.enabled — publishes;
 * this only decides which reviews are worth handing it.
 *
 * The monthly allowance is checked before each dispatch rather than trusted to
 * the generation job alone: a shop with two hundred unanswered reviews would
 * otherwise queue two hundred jobs to burn through an allowance of thirty.
 */
class AutoReplyReviewsJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'ai';

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public Location $location)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(FeatureResolver $features, UsageTracker $usage, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->location->organization_id, function () use ($features, $usage) {
            $organization = $this->location->organization;

            if ($organization === null || ! $organization->isActive()) {
                return;
            }

            // Automatic replying is the plan feature; without it a draft would
            // sit waiting for a person who never asked for one.
            if (! $features->allows(Feature::ReviewAutoReplyEnabled, $organization)
                || ! $features->allows(Feature::ReviewAiReplyEnabled, $organization)) {
                return;
            }

            $remaining = $usage->remaining(Feature::ReviewAiReplyMonthlyLimit, $organization);

            if ($remaining !== null && $remaining <= 0) {
                return;
            }

            $reviews = Review::acrossTenants()
                ->where('location_id', $this->location->getKey())
                ->unanswered()
                ->whereNull('ai_reply_status')
                ->orderByDesc('reviewed_at')
                ->limit($remaining ?? 100)
                ->get();

            foreach ($reviews as $review) {
                AiReplyGenerationJob::dispatch($review);
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'ai',
            'auto-reply',
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
