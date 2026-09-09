<?php

namespace App\Providers;

use App\Models\Organization;
use App\Services\Ranking\DataForSEOProvider;
use App\Services\Ranking\FallbackProvider;
use App\Services\Ranking\RankProviderRouter;
use App\Services\Reports\ReportPdfRenderer;
use App\Support\Tenancy;
use Illuminate\Http\Client\Factory as HttpFactory;
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

        // The webhook route is declared in bootstrap/app.php so it can point at
        // this application's controller.
        Cashier::ignoreRoutes();

        $this->registerRankProviders();

        $this->app->singleton(ReportPdfRenderer::class, fn ($app) => new ReportPdfRenderer(
            (array) $app['config']->get('reports.font', []),
        ));
    }

    /**
     * Rank sources are tried in the order registered, so the one that cannot
     * fail goes last and every check ends with something to record.
     */
    protected function registerRankProviders(): void
    {
        $this->app->singleton(DataForSEOProvider::class, fn ($app) => new DataForSEOProvider(
            $app->make(HttpFactory::class),
            (array) $app['config']->get('services.dataforseo', []),
        ));

        $this->app->singleton(RankProviderRouter::class, fn ($app) => new RankProviderRouter([
            $app->make(DataForSEOProvider::class),
            $app->make(FallbackProvider::class),
        ]));
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
