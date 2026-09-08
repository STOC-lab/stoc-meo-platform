<?php

namespace App\Models;

use App\Enums\Feature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscribable plan. Plans are grouped by product (MEO or Instagram) and
 * ranked by tier; what each one actually unlocks lives in plan_features.
 */
#[Fillable([
    'code',
    'name',
    'product',
    'tier',
    'description',
    'price',
    'currency',
    'interval',
    'stripe_price_id',
    'trial_days',
    'sort_order',
    'is_active',
])]
class Plan extends Model
{
    use HasFactory;

    public const PRODUCT_MEO = 'meo';

    public const PRODUCT_INSTAGRAM = 'ig';

    public const TIER_FREE = 'free';

    public const TIER_LIGHT = 'light';

    public const TIER_STANDARD = 'standard';

    public const TIER_PREMIUM = 'premium';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeProduct(Builder $query, string $product): void
    {
        $query->where('product', $product);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    /**
     * The value stored for a feature on this plan, or null when the plan does
     * not mention it.
     */
    public function feature(Feature|string $feature): ?PlanFeature
    {
        $key = $feature instanceof Feature ? $feature->value : $feature;

        return $this->features->firstWhere('key', $key);
    }
}
