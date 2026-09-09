<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContentCampaignRequest;
use App\Http\Requests\UpdateContentCampaignRequest;
use App\Jobs\GenerateCampaignContentJob;
use App\Jobs\InstagramPublishJob;
use App\Jobs\PublishCampaignPostJob;
use App\Models\ContentCampaign;
use App\Models\ContentCampaignPost;
use App\Models\Location;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Content campaigns for one store front.
 *
 * The store front is the root of the URL and is bound before the tenant
 * middleware has run, so every action checks that it belongs to the active
 * organization before touching it.
 */
class ContentCampaignController extends Controller
{
    public function __construct(protected Tenancy $tenancy) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', ContentCampaign::class);

        $campaigns = $location->campaigns()
            ->with('posts')
            ->latest('id')
            ->get();

        return response()->json([
            'campaigns' => $campaigns->map(fn (ContentCampaign $campaign) => $this->present($campaign))->all(),
        ]);
    }

    public function show(Location $location, ContentCampaign $campaign): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('view', $campaign);

        return response()->json([
            'campaign' => $this->present($campaign->load('posts', 'createdBy')),
        ]);
    }

    public function store(StoreContentCampaignRequest $request, Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('create', ContentCampaign::class);

        $validated = $request->validated();
        $channels = $validated['channels'];
        unset($validated['channels']);

        $campaign = $location->campaigns()->create([
            ...$validated,
            'status' => CampaignStatus::Draft,
            'created_by_user_id' => $request->user()->getKey(),
        ]);

        $campaign->addChannels(array_map(fn (string $channel) => CampaignChannel::from($channel), $channels));

        return response()->json([
            'campaign' => $this->present($campaign->load('posts')),
        ], 201);
    }

    public function update(UpdateContentCampaignRequest $request, Location $location, ContentCampaign $campaign): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('update', $campaign);

        $validated = $request->validated();
        $channels = $validated['channels'] ?? null;
        unset($validated['channels']);

        $campaign->update($validated);

        if ($channels !== null) {
            $campaign->addChannels(array_map(fn (string $channel) => CampaignChannel::from($channel), $channels));
        }

        return response()->json([
            'campaign' => $this->present($campaign->fresh()->load('posts')),
        ]);
    }

    /**
     * Call the campaign off. The posts go with it, so nothing that was part of
     * it can still publish.
     */
    public function destroy(Location $location, ContentCampaign $campaign): Response
    {
        $this->authorizeLocation($location);
        $this->authorize('delete', $campaign);

        $campaign->posts()
            ->whereNotIn('status', [CampaignPostStatus::Published, CampaignPostStatus::Failed])
            ->update(['status' => CampaignPostStatus::Cancelled]);

        $campaign->forceFill(['status' => CampaignStatus::Cancelled])->save();

        return response()->noContent();
    }

    /**
     * Hand the campaign's un-written posts to the model.
     */
    public function generate(Request $request, Location $location, ContentCampaign $campaign): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('generate', $campaign);

        abort_unless(
            $campaign->status->isRunnable(),
            422,
            'このキャンペーンは終了しているため、生成できません。',
        );

        $posts = $campaign->posts()
            ->whereIn('status', [CampaignPostStatus::Pending, CampaignPostStatus::Failed])
            ->get();

        abort_if($posts->isEmpty(), 422, '生成対象の投稿がありません。');

        foreach ($posts as $post) {
            // A failed post is put back to pending so the generation job can
            // claim it the same way it claims a new one.
            if ($post->status === CampaignPostStatus::Failed) {
                $post->forceFill(['status' => CampaignPostStatus::Pending, 'last_error' => null])->save();
            }

            GenerateCampaignContentJob::dispatch($post);
        }

        $campaign->forceFill(['status' => CampaignStatus::Active])->save();

        return response()->json([
            'campaign' => $this->present($campaign->fresh()->load('posts')),
            'queued' => $posts->count(),
        ], 202);
    }

    /**
     * Approve one written post and hand it to its channel.
     */
    public function approve(Request $request, Location $location, ContentCampaign $campaign, ContentCampaignPost $post): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('approve', $campaign);

        abort_unless(
            $post->campaign_id === $campaign->getKey(),
            404,
        );

        abort_unless(
            $post->status->needsApproval(),
            422,
            'この投稿は承認待ちではありません。',
        );

        $post->forceFill([
            'status' => CampaignPostStatus::Approved,
            'approved_by_user_id' => $request->user()->getKey(),
            'approved_at' => now(),
        ])->save();

        // Each channel has its own job, so one failing leaves the rest alone.
        match ($post->channel) {
            CampaignChannel::Instagram => InstagramPublishJob::dispatch($post),
            default => PublishCampaignPostJob::dispatch($post),
        };

        return response()->json([
            'post' => $this->presentPost($post),
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
     * @return array<string, mixed>
     */
    protected function present(ContentCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'theme' => $campaign->theme,
            'source_image_path' => $campaign->source_image_path,
            'campaign_type' => $campaign->campaign_type->value,
            'campaign_type_label' => $campaign->campaign_type->label(),
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'created_by' => $campaign->createdBy?->name,
            'posts' => $campaign->posts->map(fn (ContentCampaignPost $post) => $this->presentPost($post))->all(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentPost(ContentCampaignPost $post): array
    {
        return [
            'id' => $post->id,
            'channel' => $post->channel->value,
            'channel_label' => $post->channel->label(),
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'ai_content' => $post->ai_content,
            'ai_hashtags' => $post->ai_hashtags ?? [],
            'platform_post_id' => $post->platform_post_id,
            'retry_count' => $post->retry_count,
            'max_retries' => $post->max_retries,
            'last_error' => $post->last_error,
            'approved_at' => $post->approved_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
