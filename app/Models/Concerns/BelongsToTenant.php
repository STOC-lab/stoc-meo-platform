<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\TenantScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds organization ownership to a model: every query is scoped to the active
 * organization and new records inherit it automatically.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $tenancy = app(Tenancy::class);

            if ($model->getAttribute($model->getTenantColumn()) === null && $tenancy->check()) {
                $model->setAttribute($model->getTenantColumn(), $tenancy->id());
            }
        });
    }

    /**
     * The column holding the owning organization key.
     */
    public function getTenantColumn(): string
    {
        return 'organization_id';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Query across every organization, ignoring the active tenant.
     *
     * @return Builder<static>
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
