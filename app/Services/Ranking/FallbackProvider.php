<?php

namespace App\Services\Ranking;

use App\Models\Keyword;

/**
 * The provider of last resort: it never calls anything and never fails.
 *
 * When no upstream source could answer, this records the check as "not found"
 * along with the search a person can run themselves, so the history keeps one
 * row per day and a gap is visible in the product rather than silent.
 */
class FallbackProvider implements RankProviderInterface
{
    public const NAME = 'fallback';

    public function name(): string
    {
        return self::NAME;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * The point the check was meant to run from makes no difference to an
     * answer that is already "nothing was found".
     */
    public function fetch(Keyword $keyword, ?GeoPoint $from = null): RankResult
    {
        return RankResult::notFound($this->name(), $this->searchUrl($keyword));
    }

    /**
     * A Google Maps search for the keyword, for someone to check by hand.
     */
    protected function searchUrl(Keyword $keyword): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($keyword->keyword);
    }
}
