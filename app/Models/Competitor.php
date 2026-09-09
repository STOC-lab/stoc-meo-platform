<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\BelongsToTenant;
use App\Services\FeatureResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rival store front watched alongside one of the organization's own.
 *
 * How many a store may watch is an entitlement — competitor.limit — counted
 * from these rows rather than metered, so removing one gives the slot back.
 */
#[Fillable(['organization_id', 'location_id', 'name', 'gbp_place_id'])]
class Competitor extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * How many competitors the plan allows this store front, or null when the
     * allowance is unlimited. A plan that does not grant the feature allows
     * none.
     */
    public static function allowanceFor(Location $location): ?int
    {
        return app(FeatureResolver::class)->limit(
            Feature::CompetitorLimit,
            $location->organization,
        );
    }

    /**
     * How many more competitors the store front may watch, or null when the
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
