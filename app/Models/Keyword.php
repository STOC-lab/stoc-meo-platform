<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\BelongsToTenant;
use App\Services\FeatureResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A search term whose local rank is tracked for one store front.
 *
 * How many a store may track is an entitlement — ranking.keyword_limit — and
 * it is counted from the rows themselves rather than metered, so pausing a
 * keyword does not give the allowance back; deleting one does.
 */
#[Fillable(['organization_id', 'location_id', 'keyword', 'is_active'])]
class Keyword extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<RankingResult, $this>
     */
    public function rankingResults(): HasMany
    {
        return $this->hasMany(RankingResult::class);
    }

    /**
     * @return HasOne<RankingResult, $this>
     */
    public function latestRankingResult(): HasOne
    {
        return $this->hasOne(RankingResult::class)->latestOfMany('checked_at');
    }

    /**
     * @param  Builder<Keyword>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * How many keywords the plan allows this store front, or null when the
     * allowance is unlimited. A plan that does not grant the feature allows
     * none.
     */
    public static function allowanceFor(Location $location): ?int
    {
        return app(FeatureResolver::class)->limit(
            Feature::RankingKeywordLimit,
            $location->organization,
        );
    }

    /**
     * How many more keywords the store front may track, or null when the
     * allowance is unlimited.
     */
    public static function remainingAllowanceFor(Location $location): ?int
    {
        $allowance = static::allowanceFor($location);

        if ($allowance === null) {
            return null;
        }

        return max(0, $allowance - static::countFor($location));
    }

    public static function countFor(Location $location): int
    {
        return static::acrossTenants()
            ->where('location_id', $location->getKey())
            ->count();
    }
}
