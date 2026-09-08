<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root: every brand, location and membership hangs off an
 * organization, and billing is tracked against it.
 */
#[Fillable(['name', 'slug', 'stripe_customer_id', 'stripe_subscription_id', 'plan_id', 'status'])]
class Organization extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING], true);
    }
}
