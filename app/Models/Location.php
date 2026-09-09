<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Ranking\GeoPoint;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    'latitude',
    'longitude',
])]
class Location extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

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
     * @return HasMany<Competitor, $this>
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /**
     * @return HasOne<GbpAccount, $this>
     */
    public function gbpAccount(): HasOne
    {
        return $this->hasOne(GbpAccount::class);
    }

    /**
     * @return HasOne<InstagramAccount, $this>
     */
    public function instagramAccount(): HasOne
    {
        return $this->hasOne(InstagramAccount::class);
    }

    /**
     * @return HasMany<MeoScore, $this>
     */
    public function meoScores(): HasMany
    {
        return $this->hasMany(MeoScore::class);
    }

    /**
     * @return HasMany<ImprovementProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(ImprovementProposal::class);
    }

    /**
     * @return HasMany<Analysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    /**
     * @return HasMany<ContentCampaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(ContentCampaign::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<GbpPost, $this>
     */
    public function gbpPosts(): HasMany
    {
        return $this->hasMany(GbpPost::class);
    }

    /**
     * @return HasMany<GbpPerformanceMetric, $this>
     */
    public function gbpPerformanceMetrics(): HasMany
    {
        return $this->hasMany(GbpPerformanceMetric::class);
    }

    /**
     * @return HasMany<HeatmapRun, $this>
     */
    public function heatmapRuns(): HasMany
    {
        return $this->hasMany(HeatmapRun::class);
    }

    /**
     * Whether the location has been linked to a Google Business Profile.
     */
    public function isLinkedToGbp(): bool
    {
        return filled($this->gbp_location_id);
    }

    /**
     * Whether the store front is placed on the map, which a heatmap needs
     * before it has anywhere to lay its grid.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * The point a heatmap's grid is centred on, or null when the store front
     * has not been placed yet.
     */
    public function coordinate(): ?GeoPoint
    {
        return $this->hasCoordinates()
            ? new GeoPoint((float) $this->latitude, (float) $this->longitude)
            : null;
    }
}
