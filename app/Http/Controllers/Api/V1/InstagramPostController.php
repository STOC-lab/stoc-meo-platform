<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CampaignChannel;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Models\ContentCampaign;
use App\Models\ContentCampaignPost;
use App\Models\InstagramAccount;
use App\Services\FeatureResolver;
use App\Services\UsageTracker;
use Illuminate\Http\JsonResponse;

/**
 * Every Instagram post the organization's campaigns have made, across its
 * store fronts.
 *
 * Instagram posts only exist as campaign posts for now, so this reads
 * content_campaign_posts rather than a table of its own. Reading stays open on
 * every plan, so a downgrade does not hide what was already published; the
 * allowance says what is left to spend.
 */
class InstagramPostController extends Controller
{
    public const PER_PAGE = 15;

    public const EXCERPT_LENGTH = 100;

    public function __construct(
        protected FeatureResolver $features,
        protected UsageTracker $usage,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ContentCampaign::class);

        $posts = ContentCampaignPost::query()
            ->where('channel', CampaignChannel::Instagram)
            ->with('campaign.location')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'posts' => collect($posts->items())
                ->map(fn (ContentCampaignPost $post) => $this->present($post))
                ->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
            'allowance' => [
                'limit' => $this->features->limit(Feature::InstagramPostMonthlyLimit),
                'used' => $this->usage->used(Feature::InstagramPostMonthlyLimit),
                'remaining' => $this->usage->remaining(Feature::InstagramPostMonthlyLimit),
            ],
            'connected' => InstagramAccount::query()->exists(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(ContentCampaignPost $post): array
    {
        $location = $post->campaign?->location;

        return [
            'id' => $post->id,
            'campaign_id' => $post->campaign_id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'excerpt' => $this->excerpt($post->ai_content),
            'location' => $location === null ? null : ['id' => $location->id, 'name' => $location->name],
            'posted_at' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * The opening of the caption, counted in characters. Str::limit() counts
     * display width, which would halve a Japanese caption.
     */
    protected function excerpt(?string $content): ?string
    {
        if ($content === null || mb_strlen($content) <= self::EXCERPT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::EXCERPT_LENGTH).'…';
    }
}
