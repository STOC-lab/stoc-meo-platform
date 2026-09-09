<?php

namespace App\Services\MEO;

use App\Enums\GbpPostStatus;
use App\Enums\HeatmapRunStatus;
use App\Models\GbpPost;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\RankingResult;
use App\Models\Review;
use Carbon\CarbonImmutable;

/**
 * Scores how a store front is doing on Google, out of 100.
 *
 * Four things are measured and averaged by weight: where it ranks for the
 * keywords it tracks, how much of its neighbourhood it appears in, what its
 * customers say, and how complete its profile is. Each is scored 0-100 on its
 * own terms first, so a weak area is visible in the breakdown rather than
 * buried in one number.
 *
 * A component with nothing to measure — a store front with no keywords, or one
 * that has never run a heatmap — is left out of the average rather than scored
 * zero. Scoring it zero would punish a shop for not having bought a feature,
 * and would make the number say more about the plan than about the shop. The
 * weights of what is left are renormalised so the score stays out of 100.
 */
class MEOScoreCalculator
{
    /**
     * What each part contributes. They sum to 1.0; when a part is missing the
     * rest are scaled back up between them.
     */
    public const WEIGHTS = [
        'ranking' => 0.35,
        'heatmap' => 0.25,
        'reviews' => 0.25,
        'profile' => 0.15,
    ];

    /**
     * How far back each part looks.
     */
    public const RANKING_WINDOW_DAYS = 30;

    public const HEATMAP_WINDOW_DAYS = 90;

    /**
     * The position beyond which a rank stops being worth anything. Twentieth
     * in the local pack is not meaningfully better than not appearing.
     */
    public const RANK_FLOOR = 20;

    /**
     * Score one store front as it stands.
     *
     * @return array{score: float, breakdown: array<string, mixed>}
     */
    public function calculate(Location $location, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $components = [
            'ranking' => $this->rankingComponent($location, $at),
            'heatmap' => $this->heatmapComponent($location, $at),
            'reviews' => $this->reviewComponent($location),
            'profile' => $this->profileComponent($location),
        ];

        return [
            'score' => $this->weightedAverage($components),
            'breakdown' => $components,
        ];
    }

    /**
     * Average the components that have something to say, renormalising the
     * weights of the ones that are left.
     *
     * @param  array<string, array<string, mixed>>  $components
     */
    protected function weightedAverage(array $components): float
    {
        $total = 0.0;
        $weight = 0.0;

        foreach ($components as $key => $component) {
            if ($component['measured'] !== true) {
                continue;
            }

            $total += $component['score'] * self::WEIGHTS[$key];
            $weight += self::WEIGHTS[$key];
        }

        // Nothing measurable at all is an honest zero: there is no evidence
        // the store front is present on Google in any way.
        return $weight === 0.0 ? 0.0 : round($total / $weight, 1);
    }

    /**
     * Where the store front ranks for what it tracks, over the last month.
     *
     * First place is 100 and anything at or past the floor is 0, with a check
     * that found nothing counted as the floor rather than skipped — being
     * absent is a result.
     *
     * @return array<string, mixed>
     */
    protected function rankingComponent(Location $location, CarbonImmutable $at): array
    {
        $keywordIds = Keyword::acrossTenants()
            ->where('location_id', $location->getKey())
            ->pluck('id');

        if ($keywordIds->isEmpty()) {
            return $this->unmeasured('計測中のキーワードがありません。');
        }

        $results = RankingResult::acrossTenants()
            ->where('location_id', $location->getKey())
            ->whereIn('keyword_id', $keywordIds)
            ->where('checked_at', '>=', $at->subDays(self::RANKING_WINDOW_DAYS))
            ->get(['keyword_id', 'rank', 'checked_at']);

        if ($results->isEmpty()) {
            return $this->unmeasured('この期間に順位の計測がありません。');
        }

        // The latest check per keyword, so a keyword checked daily does not
        // outweigh one checked once.
        $latest = $results
            ->sortByDesc('checked_at')
            ->unique('keyword_id');

        $scores = $latest->map(fn ($result) => $this->scoreRank($result->rank));

        $ranked = $latest->filter(fn ($result) => $result->rank !== null);

        return [
            'measured' => true,
            'score' => round($scores->avg(), 1),
            'weight' => self::WEIGHTS['ranking'],
            'detail' => [
                'keywords' => $latest->count(),
                'ranked' => $ranked->count(),
                'unranked' => $latest->count() - $ranked->count(),
                'average_rank' => $ranked->isEmpty() ? null : round($ranked->avg('rank'), 1),
                'best_rank' => $ranked->isEmpty() ? null : (int) $ranked->min('rank'),
            ],
        ];
    }

    /**
     * How much of the neighbourhood the store front appears in, from the most
     * recent finished heatmap.
     *
     * Coverage and position both matter — appearing everywhere in tenth place
     * is not the same as appearing in half the grid in first — so the two are
     * combined rather than one standing for the other.
     *
     * @return array<string, mixed>
     */
    protected function heatmapComponent(Location $location, CarbonImmutable $at): array
    {
        $run = HeatmapRun::acrossTenants()
            ->where('location_id', $location->getKey())
            ->where('status', HeatmapRunStatus::Completed)
            ->where('created_at', '>=', $at->subDays(self::HEATMAP_WINDOW_DAYS))
            ->latest('created_at')
            ->first();

        if ($run === null) {
            return $this->unmeasured('この期間に完了したヒートマップがありません。');
        }

        $points = HeatmapPoint::acrossTenants()
            ->where('heatmap_run_id', $run->getKey())
            ->get(['rank']);

        if ($points->isEmpty()) {
            return $this->unmeasured('ヒートマップに計測地点がありません。');
        }

        $ranked = $points->filter(fn ($point) => $point->rank !== null);
        $coverage = $ranked->count() / $points->count();

        // Coverage carries the most weight; where it stands where it does
        // appear carries the rest.
        $positionScore = $ranked->isEmpty()
            ? 0.0
            : $ranked->map(fn ($point) => $this->scoreRank($point->rank))->avg();

        return [
            'measured' => true,
            'score' => round(($coverage * 100 * 0.6) + ($positionScore * 0.4), 1),
            'weight' => self::WEIGHTS['heatmap'],
            'detail' => [
                'grid_size' => $run->grid_size->value,
                'points' => $points->count(),
                'ranked_points' => $ranked->count(),
                'coverage_percent' => round($coverage * 100, 1),
                'average_rank' => $ranked->isEmpty() ? null : round($ranked->avg('rank'), 1),
                'run_at' => $run->completed_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * What customers say, and whether the store front answers them.
     *
     * The star average carries most of it, but a shop that never replies is
     * marked down: replying is the part it actually controls.
     *
     * @return array<string, mixed>
     */
    protected function reviewComponent(Location $location): array
    {
        $reviews = Review::acrossTenants()
            ->where('location_id', $location->getKey())
            ->get(['rating', 'replied_at']);

        if ($reviews->isEmpty()) {
            return $this->unmeasured('口コミがまだありません。');
        }

        $rated = $reviews->filter(fn ($review) => $review->rating !== null);

        // A five-star average is 100 and a one-star average is 0.
        $ratingScore = $rated->isEmpty()
            ? 0.0
            : max(0.0, min(100.0, (($rated->avg('rating') - 1) / 4) * 100));

        $answered = $reviews->filter(fn ($review) => $review->replied_at !== null)->count();
        $replyScore = ($answered / $reviews->count()) * 100;

        return [
            'measured' => true,
            'score' => round(($ratingScore * 0.7) + ($replyScore * 0.3), 1),
            'weight' => self::WEIGHTS['reviews'],
            'detail' => [
                'total' => $reviews->count(),
                'average_rating' => $rated->isEmpty() ? null : round($rated->avg('rating'), 2),
                'answered' => $answered,
                'unanswered' => $reviews->count() - $answered,
                'reply_rate_percent' => round($replyScore, 1),
            ],
        ];
    }

    /**
     * How complete the Business Profile is, and whether it is being kept
     * alive.
     *
     * This is the one part that is always measurable: every store front has a
     * profile, even an empty one.
     *
     * @return array<string, mixed>
     */
    protected function profileComponent(Location $location): array
    {
        $checks = [
            'gbp_linked' => $location->isLinkedToGbp(),
            'coordinates' => $location->hasCoordinates(),
            'address' => filled($location->address),
            'phone' => filled($location->phone),
            'website' => filled($location->website_url),
            'posted_recently' => GbpPost::acrossTenants()
                ->where('location_id', $location->getKey())
                ->where('status', GbpPostStatus::Published)
                ->where('published_at', '>=', CarbonImmutable::now()->subDays(30))
                ->exists(),
        ];

        $met = count(array_filter($checks));

        return [
            'measured' => true,
            'score' => round(($met / count($checks)) * 100, 1),
            'weight' => self::WEIGHTS['profile'],
            'detail' => [
                'checks' => $checks,
                'met' => $met,
                'total' => count($checks),
            ],
        ];
    }

    /**
     * Turn a position into a score: first is 100, the floor and beyond is 0,
     * and a check that found nothing counts as the floor.
     */
    protected function scoreRank(?int $rank): float
    {
        if ($rank === null || $rank >= self::RANK_FLOOR) {
            return 0.0;
        }

        return round(((self::RANK_FLOOR - $rank) / (self::RANK_FLOOR - 1)) * 100, 1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function unmeasured(string $reason): array
    {
        return [
            'measured' => false,
            'score' => null,
            'weight' => 0.0,
            'reason' => $reason,
            'detail' => [],
        ];
    }
}
