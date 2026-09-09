<?php

namespace App\Providers;

use App\Models\Organization;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\ClaudeProvider;
use App\Services\Instagram\InstagramClient;
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

        $this->registerAiProviders();

        $this->app->singleton(InstagramClient::class, fn ($app) => new InstagramClient(
            $app->make(HttpFactory::class),
            (array) $app['config']->get('services.instagram', []),
        ));
    }

    /**
     * The product asks for generated text in terms of what it needs rather
     * than who supplies it, so the choice sits behind the factory.
     */
    protected function registerAiProviders(): void
    {
        $this->app->singleton(ClaudeProvider::class, fn ($app) => new ClaudeProvider(
            $app->make(HttpFactory::class),
            (array) $app['config']->get('ai.claude', []),
        ));

        $this->app->singleton(AIProviderFactory::class, fn ($app) => new AIProviderFactory(
            $app,
            [ClaudeProvider::NAME => ClaudeProvider::class],
            (string) $app['config']->get('ai.default', ClaudeProvider::NAME),
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
