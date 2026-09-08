<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the active organization.
 * When no organization is active the scope is a no-op, so console commands and
 * jobs see every row unless they opt into a tenant via Tenancy::forOrganization().
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn($model->getTenantColumn()),
            $tenancy->id(),
        );
    }
}
