<?php

namespace App\Providers;

use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Tenancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Billing is per organization, not per user: Cashier reads stripe_id
        // from organizations and keys subscriptions by organization_id.
        Cashier::useCustomerModel(Organization::class);
    }
}
