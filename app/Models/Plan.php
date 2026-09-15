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
    'billing_period_months',
    'phases',
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

    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    /**
     * A plan bought outright rather than subscribed to. The 6-month MEO
     * PREMIUM special is the only one: it is a single charge that buys a fixed
     * term, so it has no Stripe recurring price and Cashier never renews it.
     */
    public const INTERVAL_ONE_TIME = 'one_time';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'billing_period_months' => 'integer',
            'phases' => 'integer',
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
     * Whether Stripe should mint a recurring price for this plan. A one-time
     * plan is charged once and its term is carried by billing_period_months,
     * not by Stripe renewing anything.
     */
    public function isRecurring(): bool
    {
        return $this->interval !== self::INTERVAL_ONE_TIME;
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
