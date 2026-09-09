<?php

use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceUsageQuota;
use App\Http\Middleware\EnsureFeatureIsEnabled;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController as CashierPaymentController;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Stripe posts here, so the route carries neither session nor CSRF
            // middleware. Cashier's own webhook route is disabled in favour of
            // this one (see AppServiceProvider).
            Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
                ->name('cashier.webhook');

            // Cashier links to this page when a payment needs further
            // authentication, so it has to survive ignoreRoutes().
            Route::get('stripe/payment/{id}', [CashierPaymentController::class, 'show'])
                ->name('cashier.payment');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA is served from the same origin and authenticates with a
        // session cookie, so API requests from it are stateful.
        $middleware->statefulApi();

        // An unauthenticated request to an API route must be told so in JSON.
        // Laravel's default is to redirect a guest to a route named "login",
        // which does not exist here — the SPA owns /login — so without this a
        // request that did not ask for JSON gets a 500 and an error in the log
        // instead of a clean 401.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login',
        );

        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'role' => CheckRole::class,
            'feature' => EnsureFeatureIsEnabled::class,
            'quota' => EnforceUsageQuota::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
