<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\InvitationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:6,1')
        ->name('api.v1.auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('api.v1.auth.login');

    // Invitations are opened from an emailed link, by someone who may have
    // neither an account nor an organization yet.
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->name('api.v1.invitations.show');

    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')
        ->name('api.v1.invitations.accept');

    // The switcher needs the membership list before an organization can be
    // chosen, so these sit outside the tenant middleware.
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
    });
});

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant'])
    ->group(function () {
        // Payment and cancellation are admin territory.
        Route::middleware('role:admin')->group(function () {
            Route::post('billing/checkout', [BillingController::class, 'checkout'])
                ->name('api.v1.billing.checkout');

            Route::get('billing/portal', [BillingController::class, 'portal'])
                ->name('api.v1.billing.portal');

            Route::post('organizations/{organization}/invitations', [InvitationController::class, 'store'])
                ->name('api.v1.organizations.invitations.store');
        });
    });

// Unknown API paths answer in JSON rather than falling through to the SPA.
Route::fallback(fn () => response()->json(['message' => 'Not Found.'], 404));
