<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReplyToReviewRequest;
use App\Models\Location;
use App\Models\Review;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The reviews left on a store front's Business Profile.
 *
 * Google owns the review, so a reply is sent there first and only written down
 * here once it has landed — a reply stored on a call that failed would show
 * the store as answered when it is not.
 */
class ReviewController extends Controller
{
    public function __construct(
        protected Tenancy $tenancy,
        protected GBPClientFactory $clients,
    ) {}

    public function index(Location $location): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('viewAny', Review::class);

        $reviews = $location->reviews()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'reviews' => $reviews->map(fn (Review $review) => $this->present($review))->all(),
            'summary' => [
                'total' => $reviews->count(),
                'unanswered' => $reviews->filter(fn (Review $review) => ! $review->isAnswered())->count(),
                'average_rating' => $this->averageRating($reviews),
            ],
        ]);
    }

    /**
     * Answer a review, on Google and then here.
     */
    public function reply(ReplyToReviewRequest $request, Location $location, Review $review): JsonResponse
    {
        $this->authorizeLocation($location);
        $this->authorize('reply', $review);

        $comment = (string) $request->validated('reply');

        try {
            $account = $this->clients->connectionFor($location);

            $this->clients->reviews($account)->reply(
                (string) $account->gbp_account_name,
                (string) $location->gbp_location_id,
                $review->google_review_id,
                $comment,
            );
        } catch (GBPAuthenticationException $e) {
            return response()->json([
                'message' => 'Googleビジネスプロフィールとの連携が切れています。再接続してください。',
                'reconnect_required' => true,
            ], 409);
        } catch (GBPException $e) {
            return response()->json([
                'message' => 'Googleへの返信に失敗しました。時間をおいて再度お試しください。',
            ], 502);
        }

        $review->forceFill([
            'reply' => $comment,
            'replied_at' => now(),
        ])->save();

        return response()->json(['review' => $this->present($review)]);
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
     * @param  Collection<int, Review>  $reviews
     */
    protected function averageRating($reviews): ?float
    {
        $rated = $reviews->filter(fn (Review $review) => $review->rating !== null);

        return $rated->isEmpty() ? null : round($rated->avg('rating'), 1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Review $review): array
    {
        return [
            'id' => $review->id,
            'google_review_id' => $review->google_review_id,
            'author_name' => $review->author_name,
            'author_photo_url' => $review->author_photo_url,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'reply' => $review->reply,
            'answered' => $review->isAnswered(),
            'replied_at' => $review->replied_at?->toIso8601String(),
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }
}
