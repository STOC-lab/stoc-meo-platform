<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTenantSlug;
use App\Services\Ranking\GeoPoint;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single store front, mapped to its Google Business Profile location.
 *
 * `address` holds the whole address on one line and is what the Business
 * Profile sync writes. The broken-out parts beside it are what a person types,
 * and neither is derived from the other.
 *
 * Deletion is soft: ten tables of history hang off a store front, and taking
 * it out of the product should not take the measurements with it.
 */
#[Fillable([
    'organization_id',
    'brand_id',
    'name',
    'gbp_location_id',
    'website_url',
    'phone',
    'postal_code',
    'prefecture',
    'city',
    'address',
    'latitude',
    'longitude',
    'google_place_id',
    'google_maps_url',
    'is_active',
])]
class Location extends Model
{
    use BelongsToTenant, HasFactory, HasTenantSlug, SoftDeletes;

    /**
     * The column default lives in the database, but a model that has just been
     * created has to answer for itself before it is read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function slugFallback(): string
    {
        return 'store';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only the store fronts that are being run right now. A paused one keeps
     * its history and its keywords; it is simply not measured or shown.
     *
     * @param  Builder<Location>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
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
