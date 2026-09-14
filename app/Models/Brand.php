<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTenantSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A brand groups the locations of one organization (e.g. a chain's banner).
 *
 * The slug is derived from the name and unique within the organization; it is
 * not accepted from the client, because a name that changes should not leave a
 * slug behind that says something else.
 */
#[Fillable(['organization_id', 'name', 'logo_url', 'description', 'website_url'])]
class Brand extends Model
{
    use BelongsToTenant, HasFactory, HasTenantSlug, SoftDeletes;

    protected function slugFallback(): string
    {
        return 'brand';
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
