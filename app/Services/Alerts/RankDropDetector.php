<?php

namespace App\Services\Alerts;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Keyword;
use App\Models\RankingResult;
use Illuminate\Support\Collection;

/**
 * Raises an alert when a keyword's rank falls sharply between one check and
 * the one before it.
 *
 * The comparison is against the previous observation rather than a rolling
 * average: a store front that was third yesterday and twelfth today is what
 * the alert is for, and averaging that away would be the opposite of useful.
 * Falling out of the results entirely counts as a drop however far down the
 * store front was, since the position it lost cannot be measured.
 */
class RankDropDetector
{
    /**
     * How many positions a keyword has to fall before it is worth saying so,
     * as settled in design v1.3.
     */
    public const THRESHOLD = 5;

    /**
     * Compare the keyword's two most recent checks and raise an alert if the
     * later one fell far enough. Returns the alert, or null when there was
     * nothing to say.
     */
    public function detect(Keyword $keyword): ?Alert
    {
        [$current, $previous] = $this->lastTwoResults($keyword);

        if ($current === null || $previous === null || $previous->rank === null) {
            return null;
        }

        $drop = $this->dropBetween($previous->rank, $current->rank);

        if ($drop === null || $drop < self::THRESHOLD) {
            return null;
        }

        // The sweep runs once a day, but a re-run of it should not say the
        // same thing twice about the same check.
        if ($this->alreadyRaised($keyword, $current)) {
            return null;
        }

        return Alert::create([
            'organization_id' => $keyword->organization_id,
            'location_id' => $keyword->location_id,
            'keyword_id' => $keyword->getKey(),
            'type' => AlertType::RankDrop,
            'payload' => [
                'keyword' => $keyword->keyword,
                'previous_rank' => $previous->rank,
                'current_rank' => $current->rank,
                'drop' => $drop,
                'previous_checked_at' => $previous->checked_at->toIso8601String(),
                'checked_at' => $current->checked_at->toIso8601String(),
            ],
            'is_read' => false,
        ]);
    }

    /**
     * How far the store front fell, or null when it did not fall at all. A
     * check that found nothing is treated as a drop past the threshold, since
     * how far it fell is not knowable.
     */
    protected function dropBetween(int $previousRank, ?int $currentRank): ?int
    {
        if ($currentRank === null) {
            return self::THRESHOLD;
        }

        $drop = $currentRank - $previousRank;

        return $drop > 0 ? $drop : null;
    }

    /**
     * The keyword's latest check and the one before it, newest first.
     *
     * @return array{0: RankingResult|null, 1: RankingResult|null}
     */
    protected function lastTwoResults(Keyword $keyword): array
    {
        /** @var Collection<int, RankingResult> $results */
        $results = RankingResult::acrossTenants()
            ->where('keyword_id', $keyword->getKey())
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        return [$results->get(0), $results->get(1)];
    }

    /**
     * Whether the keyword's latest alert is already about this same check.
     *
     * The comparison is made in PHP against the one row that could match,
     * rather than by querying inside the payload, so it does not depend on
     * what the database can do with JSON.
     */
    protected function alreadyRaised(Keyword $keyword, RankingResult $current): bool
    {
        $latest = Alert::acrossTenants()
            ->where('keyword_id', $keyword->getKey())
            ->where('type', AlertType::RankDrop)
            ->latest('id')
            ->first();

        return $latest !== null
            && ($latest->payload['checked_at'] ?? null) === $current->checked_at->toIso8601String();
    }
}
