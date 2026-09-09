<?php

namespace App\Services\MEO;

use App\Enums\AlertType;
use App\Enums\GbpPostStatus;
use App\Models\Alert;
use App\Models\GbpPost;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\MeoScore;
use App\Models\RankingResult;
use App\Models\Review;
use Carbon\CarbonImmutable;

/**
 * Gathers the figures an analysis is written from.
 *
 * Everything is read across tenants and filtered by store front explicitly,
 * because an analysis is built by a queued job rather than inside a request
 * and must not depend on a tenant having been set.
 */
class AnalysisFigures
{
    /**
     * @return array<string, mixed>
     */
    public function gather(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'score' => $this->score($location, $end)?->score,
            'score_change' => $this->scoreChange($location, $start, $end),
            'ranking' => $this->ranking($location, $start, $end),
            'reviews' => $this->reviews($location, $start, $end),
            'posts' => $this->posts($location, $start, $end),
            'alerts' => Alert::acrossTenants()
                ->where('location_id', $location->getKey())
                ->where('type', AlertType::RankDrop)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
        ];
    }

    protected function score(Location $location, CarbonImmutable $end): ?MeoScore
    {
        return MeoScore::acrossTenants()
            ->where('location_id', $location->getKey())
            ->where('calculated_at', '<=', $end->endOfDay())
            ->latest('calculated_at')
            ->first();
    }

    /**
     * How far the score moved over the period, or null when there is nothing
     * to compare against.
     */
    protected function scoreChange(Location $location, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        $latest = $this->score($location, $end);

        if ($latest === null) {
            return null;
        }

        $earlier = MeoScore::acrossTenants()
            ->where('location_id', $location->getKey())
            ->where('calculated_at', '<', $start)
            ->latest('calculated_at')
            ->first();

        return $earlier === null ? null : round($latest->score - $earlier->score, 1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ranking(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $results = RankingResult::acrossTenants()
            ->where('location_id', $location->getKey())
            ->whereBetween('checked_at', [$start, $end])
            ->get(['rank']);

        $ranked = $results->filter(fn ($result) => $result->rank !== null);

        return [
            'keywords' => Keyword::acrossTenants()->where('location_id', $location->getKey())->count(),
            'checks' => $results->count(),
            'unranked' => $results->count() - $ranked->count(),
            'average_rank' => $ranked->isEmpty() ? null : round($ranked->avg('rank'), 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviews(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $arrived = Review::acrossTenants()
            ->where('location_id', $location->getKey())
            ->whereBetween('reviewed_at', [$start, $end])
            ->get(['rating']);

        $rated = $arrived->filter(fn ($review) => $review->rating !== null);

        return [
            'new' => $arrived->count(),
            'average_rating' => $rated->isEmpty() ? null : round($rated->avg('rating'), 2),
            // Unanswered is counted over everything, not just this period: an
            // old review left hanging is still hanging.
            'unanswered' => Review::acrossTenants()
                ->where('location_id', $location->getKey())
                ->unanswered()
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function posts(Location $location, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'gbp' => GbpPost::acrossTenants()
                ->where('location_id', $location->getKey())
                ->where('status', GbpPostStatus::Published)
                ->whereBetween('published_at', [$start, $end])
                ->count(),
        ];
    }
}
