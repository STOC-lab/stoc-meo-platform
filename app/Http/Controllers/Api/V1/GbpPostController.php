<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Feature;
use App\Enums\GbpPostStatus;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGbpPostRequest;
use App\Jobs\PublishGbpPostJob;
use App\Models\GbpPost;
use App\Models\Location;
use App\Services\FeatureResolver;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;

/**
 * The posts published to a store front's Business Profile.
 *
 * A post is stored as a draft and handed to a worker rather than published in
 * the request: the call to Google is slow and can fail, and neither should be
 * something the person waits on. The monthly allowance is checked here so the
 * refusal is immediate, and charged by the worker so a post that never
 * publishes costs the organization nothing.
 */
class GbpPostController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected FeatureResolver $features,
        protected UsageTracker $usage,
    ) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', GbpPost::class);

        $posts = $location->gbpPosts()->latest('id')->get();

        return response()->json([
            'posts' => $posts->map(fn (GbpPost $post) => $this->present($post))->all(),
            'allowance' => $this->allowance(),
        ]);
    }

    /**
     * @throws QuotaExceededException
     */
    public function store(StoreGbpPostRequest $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('create', GbpPost::class);
        $this->guardPostAllowance();

        $post = $location->gbpPosts()->create([
            ...$request->validated(),
            'status' => GbpPostStatus::Draft,
        ]);

        PublishGbpPostJob::dispatch($post);

        return response()->json([
            'post' => $this->present($post),
            'allowance' => $this->allowance(),
        ], 202);
    }

    /**
     * Route model binding resolves the store front before the tenant is known,
     * so it can be one from another organization. Answer as though it does not
     * exist rather than confirming it does.
     */
    protected function authorizeLocation(Location $location): void
    {
        abort_unless($location->organization_id === $this->tenancy->id(), 404);
    }

    /**
     * This only checks; the worker is what moves the counter, once the post
     * has actually been sent.
     *
     * @throws QuotaExceededException
     */
    protected function guardPostAllowance(): void
    {
        $limit = $this->features->limit(Feature::GbpPostMonthlyLimit);

        if ($limit === null) {
            return;
        }

        $used = $this->usage->used(Feature::GbpPostMonthlyLimit);

        if ($used >= $limit) {
            throw QuotaExceededException::for(Feature::GbpPostMonthlyLimit, $limit, $used);
        }
    }

    /**
     * @return array<string, int|null>
     */
    protected function allowance(): array
    {
        return [
            'limit' => $this->features->limit(Feature::GbpPostMonthlyLimit),
            'used' => $this->usage->used(Feature::GbpPostMonthlyLimit),
            'remaining' => $this->usage->remaining(Feature::GbpPostMonthlyLimit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(GbpPost $post): array
    {
        return [
            'id' => $post->id,
            'content' => $post->content,
            'media_url' => $post->media_url,
            'cta_type' => $post->cta_type,
            'cta_url' => $post->cta_url,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'gbp_post_id' => $post->gbp_post_id,
            'failure_reason' => $post->failure_reason,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
