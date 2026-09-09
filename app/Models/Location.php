<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single store front, mapped to its Google Business Profile location.
 */
#[Fillable([
    'organization_id',
    'brand_id',
    'name',
    'gbp_location_id',
    'website_url',
    'phone',
    'address',
])]
class Location extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<Keyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    /**
     * @return HasMany<RankingResult, $this>
     */
    public function rankingResults(): HasMany
    {
        return $this->hasMany(RankingResult::class);
    }

    /**
     * Whether the location has been linked to a Google Business Profile.
     */
    public function isLinkedToGbp(): bool
    {
        return filled($this->gbp_location_id);
    }
}
