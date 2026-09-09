<?php

namespace App\Services\Ranking;

use Carbon\CarbonImmutable;

/**
 * What a provider found for one keyword: the position the store front holds in
 * the local results, or nothing at all when it did not appear.
 */
final class RankResult
{
    public readonly CarbonImmutable $checkedAt;

    public function __construct(
        public readonly ?int $rank,
        public readonly string $provider,
        public readonly ?string $searchUrl = null,
        ?CarbonImmutable $checkedAt = null,
    ) {
        $this->checkedAt = $checkedAt ?? CarbonImmutable::now();
    }

    /**
     * The provider answered, but the store front was not among the results.
     */
    public static function notFound(string $provider, ?string $searchUrl = null): self
    {
        return new self(null, $provider, $searchUrl);
    }

    public function isRanked(): bool
    {
        return $this->rank !== null;
    }
}
