<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CompetitorController;
use App\Http\Controllers\Api\V1\ContentCampaignController;
use App\Http\Controllers\Api\V1\GbpPerformanceController;
use App\Http\Controllers\Api\V1\GbpPostController;
use App\Http\Controllers\Api\V1\GoogleConnectionController;
use App\Http\Controllers\Api\V1\HeatmapController;
use App\Http\Controllers\Api\V1\ImprovementProposalController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\KeywordController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MeoInsightController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
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

    // The browser arrives here from Google's consent screen carrying nothing
    // of ours but the state we put in the request, so this route has neither
    // the tenant middleware nor a session to rely on. The state is what says
    // which store front was being connected.
    Route::get('auth/google/callback', [GoogleConnectionController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->name('api.v1.auth.google.callback');

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

                    Route::post('keywords/{keyword}/check', [KeywordController::class, 'check'])
                        ->name('api.v1.keywords.check');
                });
            });

        // The two heatmap grids are entitled separately, so the group carries
        // no blanket feature gate: which grid a plan includes is checked
        // against the size actually asked for, and reading past runs stays
        // open so a plan change does not hide the maps already paid for.
        Route::prefix('locations/{location}')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('heatmaps', [HeatmapController::class, 'index'])
                        ->name('api.v1.heatmaps.index');

                    Route::get('heatmaps/{heatmapRun}', [HeatmapController::class, 'show'])
                        ->name('api.v1.heatmaps.show');
                });

                // A run spends the plan's monthly allowance, so asking for one
                // is a store manager's call.
                Route::middleware('role:location_admin')->group(function () {
                    Route::post('heatmaps', [HeatmapController::class, 'store'])
                        ->name('api.v1.heatmaps.store');
                });
            });

        // Starting the connection is done from inside the application, so
        // unlike the callback it has a tenant and a signed-in administrator.
        Route::middleware('role:org_admin')->group(function () {
            Route::get('auth/google/redirect', [GoogleConnectionController::class, 'redirect'])
                ->name('api.v1.auth.google.redirect');

            Route::get('auth/google/connection', [GoogleConnectionController::class, 'show'])
                ->name('api.v1.auth.google.connection');
        });

        // The Business Profile module. Reading what Google reported is open to
        // every member; speaking for the business is not.
        Route::prefix('locations/{location}')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('gbp/performance', [GbpPerformanceController::class, 'index'])
                        ->name('api.v1.gbp.performance.index');

                    Route::get('reviews', [ReviewController::class, 'index'])
                        ->name('api.v1.reviews.index');

                    Route::get('gbp-posts', [GbpPostController::class, 'index'])
                        ->name('api.v1.gbp-posts.index');
                });

                // Answering a review is day-to-day work for whoever runs the
                // shop floor.
                Route::middleware('role:staff')->group(function () {
                    Route::post('reviews/{review}/reply', [ReviewController::class, 'reply'])
                        ->name('api.v1.reviews.reply');
                });

                // Asking a model for a draft reply spends the plan's monthly
                // allowance, so it is gated on the plan including AI replies
                // at all. Approving one is what actually speaks to a customer.
                Route::middleware(['role:staff', 'feature:review.ai_reply.enabled'])->group(function () {
                    Route::post('reviews/{review}/ai-reply', [ReviewController::class, 'aiReply'])
                        ->name('api.v1.reviews.ai-reply');

                    Route::post('reviews/{review}/ai-reply/approve', [ReviewController::class, 'approveAiReply'])
                        ->name('api.v1.reviews.ai-reply.approve');
                });

                // Publishing spends the plan's monthly allowance, so it is a
                // store manager's call and is gated on the plan granting any.
                Route::middleware(['role:location_admin', 'feature:gbp.post.monthly_limit'])->group(function () {
                    Route::post('gbp-posts', [GbpPostController::class, 'store'])
                        ->name('api.v1.gbp-posts.store');
                });
            });

        // What the organization needs to know about: a rank that fell, a
        // connection that needs reconnecting. Raised by the jobs that notice
        // them, so there is nothing to create here.
        Route::prefix('locations/{location}')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('alerts', [AlertController::class, 'index'])
                        ->name('api.v1.alerts.index');
                });

                Route::middleware('role:staff')->group(function () {
                    Route::match(['put', 'patch'], 'alerts/{alert}', [AlertController::class, 'update'])
                        ->name('api.v1.alerts.update');
                });
            });

        // The MEO score and what the model made of it. The score itself is
        // calculated for every plan — it is what the rest is written from —
        // so reading it is not gated; the analyses only exist on the plans
        // that generate them, so an empty list is the honest answer there.
        Route::prefix('locations/{location}')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('meo-score', [MeoInsightController::class, 'score'])
                        ->name('api.v1.meo-score.show');

                    Route::get('analyses', [MeoInsightController::class, 'analyses'])
                        ->name('api.v1.analyses.index');

                    Route::get('proposals', [ImprovementProposalController::class, 'index'])
                        ->name('api.v1.proposals.index');
                });

                // Deciding what to do about a proposal is shop-floor work.
                Route::middleware('role:staff')->group(function () {
                    Route::match(['put', 'patch'], 'proposals/{proposal}', [ImprovementProposalController::class, 'update'])
                        ->name('api.v1.proposals.update');
                });
            });

        // Content campaigns turn one theme into posts across several
        // channels. Reading what is planned is open to every member; writing
        // one and approving what the model wrote are not.
        Route::prefix('locations/{location}')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('campaigns', [ContentCampaignController::class, 'index'])
                        ->name('api.v1.campaigns.index');

                    Route::get('campaigns/{campaign}', [ContentCampaignController::class, 'show'])
                        ->name('api.v1.campaigns.show');
                });

                Route::middleware('role:location_admin')->group(function () {
                    Route::post('campaigns', [ContentCampaignController::class, 'store'])
                        ->name('api.v1.campaigns.store');

                    Route::match(['put', 'patch'], 'campaigns/{campaign}', [ContentCampaignController::class, 'update'])
                        ->name('api.v1.campaigns.update');

                    Route::delete('campaigns/{campaign}', [ContentCampaignController::class, 'destroy'])
                        ->name('api.v1.campaigns.destroy');

                    Route::post('campaigns/{campaign}/generate', [ContentCampaignController::class, 'generate'])
                        ->name('api.v1.campaigns.generate');

                    Route::post('campaigns/{campaign}/posts/{post}/approve', [ContentCampaignController::class, 'approve'])
                        ->name('api.v1.campaigns.posts.approve');
                });
            });

        // A report covers the whole organization rather than one store front,
        // so these routes are not scoped through it: the store front in the
        // URL says which organization is meant, and the tenant scope is what
        // keeps another one's reports out of reach. Reading one is open to
        // every member, since it summarises what they already see.
        Route::prefix('locations/{location}')
            ->middleware(['feature:pdf_report.enabled', 'role:viewer'])
            ->group(function () {
                Route::get('reports', [ReportController::class, 'index'])
                    ->name('api.v1.reports.index');

                Route::get('reports/{report}', [ReportController::class, 'show'])
                    ->name('api.v1.reports.show');
            });

        // Competitor tracking is one allowance rather than two, so the whole
        // group is gated on the plan granting it at all.
        Route::prefix('locations/{location}')
            ->middleware('feature:competitor.limit')
            ->scopeBindings()
            ->group(function () {
                Route::middleware('role:viewer')->group(function () {
                    Route::get('competitors', [CompetitorController::class, 'index'])
                        ->name('api.v1.competitors.index');
                });

                Route::middleware('role:location_admin')->group(function () {
                    Route::post('competitors', [CompetitorController::class, 'store'])
                        ->name('api.v1.competitors.store');

                    Route::delete('competitors/{competitor}', [CompetitorController::class, 'destroy'])
                        ->name('api.v1.competitors.destroy');
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
