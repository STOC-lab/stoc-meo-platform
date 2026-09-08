<?php

use App\Http\Controllers\Api\V1\BillingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant'])
    ->group(function () {
        // Payment and cancellation are admin territory.
        Route::middleware('role:admin')->group(function () {
            Route::post('billing/checkout', [BillingController::class, 'checkout'])
                ->name('api.v1.billing.checkout');

            Route::get('billing/portal', [BillingController::class, 'portal'])
                ->name('api.v1.billing.portal');
        });
    });
