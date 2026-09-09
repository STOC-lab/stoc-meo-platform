<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\KeywordController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MemberController;
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
        Route::middleware('role:org_admin')->group(function () {
            Route::post('billing/checkout', [BillingController::class, 'checkout'])
                ->name('api.v1.billing.checkout');

            Route::get('billing/portal', [BillingController::class, 'portal'])
                ->name('api.v1.billing.portal');
        });

        // Rank tracking hangs off the store front rather than the
        // organization, which the tenant middleware takes from the
        // X-Organization-Id header or a sole membership. The whole group is
        // gated on the plan including ranking at all.
        Route::prefix('locations/{location}')
            ->middleware('feature:ranking.enabled')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('keywords', [KeywordController::class, 'index'])
                        ->name('api.v1.keywords.index');

                    Route::get('keywords/{keyword}', [KeywordController::class, 'show'])
                        ->name('api.v1.keywords.show');
                });

                // Choosing what to track is a store manager's job, the same
                // rank that maintains the store front itself.
                Route::middleware('role:location_admin')->group(function () {
                    Route::post('keywords', [KeywordController::class, 'store'])
                        ->name('api.v1.keywords.store');

                    Route::match(['put', 'patch'], 'keywords/{keyword}', [KeywordController::class, 'update'])
                        ->name('api.v1.keywords.update');

                    Route::delete('keywords/{keyword}', [KeywordController::class, 'destroy'])
                        ->name('api.v1.keywords.destroy');
                });
            });

        // Everything below hangs off the organization in the URL, which is
        // also what the tenant middleware pins the request to. Scoped bindings
        // resolve each record through that organization, so another tenant's
        // brand, store front, member or invitation is a 404 rather than a
        // policy decision.
        Route::prefix('organizations/{organization}')
            ->scopeBindings()
            ->group(function () {
                // The shop structure is readable by every member.
                Route::middleware('role:viewer')->group(function () {
                    Route::get('brands', [BrandController::class, 'index'])
                        ->name('api.v1.brands.index');

                    Route::get('brands/{brand}', [BrandController::class, 'show'])
                        ->name('api.v1.brands.show');

                    Route::get('locations', [LocationController::class, 'index'])
                        ->name('api.v1.locations.index');

                    Route::get('locations/{location}', [LocationController::class, 'show'])
                        ->name('api.v1.locations.show');
                });

                // Keeping a store front's details current is a store manager's
                // day-to-day work.
                Route::middleware('role:location_admin')->group(function () {
                    Route::match(['put', 'patch'], 'locations/{location}', [LocationController::class, 'update'])
                        ->name('api.v1.locations.update');
                });

                // Adding or removing a store front changes what the
                // organization is billed for, and membership decides who may
                // do anything at all, so both stay with organization
                // administrators — the member list included.
                Route::middleware('role:org_admin')->group(function () {
                    Route::get('members', [MemberController::class, 'index'])
                        ->name('api.v1.members.index');

                    Route::post('brands', [BrandController::class, 'store'])
                        ->name('api.v1.brands.store');

                    Route::match(['put', 'patch'], 'brands/{brand}', [BrandController::class, 'update'])
                        ->name('api.v1.brands.update');

                    Route::delete('brands/{brand}', [BrandController::class, 'destroy'])
                        ->name('api.v1.brands.destroy');

                    Route::post('locations', [LocationController::class, 'store'])
                        ->name('api.v1.locations.store');

                    Route::delete('locations/{location}', [LocationController::class, 'destroy'])
                        ->name('api.v1.locations.destroy');

                    Route::match(['put', 'patch'], 'members/{user}', [MemberController::class, 'update'])
                        ->name('api.v1.members.update');

                    Route::delete('members/{user}', [MemberController::class, 'destroy'])
                        ->name('api.v1.members.destroy');

                    Route::get('invitations', [InvitationController::class, 'index'])
                        ->name('api.v1.organizations.invitations.index');

                    Route::post('invitations', [InvitationController::class, 'store'])
                        ->name('api.v1.organizations.invitations.store');

                    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
                        ->name('api.v1.organizations.invitations.destroy');
                });
            });
    });

// Unknown API paths answer in JSON rather than falling through to the SPA.
Route::fallback(fn () => response()->json(['message' => 'Not Found.'], 404));
