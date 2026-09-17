<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\BillingService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Billing entry points for the active organization. Both routes are gated on
 * the admin role, since they lead to payment and cancellation.
 */
class BillingController extends Controller
{
    /**
     * Where Stripe sends the customer back to when Checkout closes.
     *
     * It has to be a route the SPA actually has. The front-end paths are the
     * flat ones in `resources/js/routes.tsx`, and billing is a tab of
     * `/settings` rather than a page of its own — `/billing/complete` and
     * `/billing/plans` were neither, so a customer who had just paid landed on
     * the not-found page. `?tab=` is what opens the tab and `?checkout=` is
     * what the page says about how it went.
     */
    public const RETURN_PATH = '/settings?tab=billing';

    public function __construct(
        protected BillingService $billing,
        protected Tenancy $tenancy,
    ) {}

    /**
     * Start a Stripe Checkout session for a plan.
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'success_url' => ['sometimes', 'url'],
            'cancel_url' => ['sometimes', 'url'],
        ]);

        $plan = Plan::where('code', $data['plan_code'])->firstOrFail();

        if (! $plan->is_active) {
            throw ValidationException::withMessages([
                'plan_code' => 'このプランは現在ご契約いただけません。',
            ]);
        }

        if (blank($plan->stripe_price_id)) {
            throw ValidationException::withMessages([
                'plan_code' => 'このプランは Stripe の価格が未設定のため、まだご契約いただけません。',
            ]);
        }

        $url = $this->billing->checkoutUrl(
            $this->tenancy->organization(),
            $plan,
            $data['success_url'] ?? $this->defaultUrl(self::RETURN_PATH.'&checkout=success'),
            $data['cancel_url'] ?? $this->defaultUrl(self::RETURN_PATH.'&checkout=cancelled'),
        );

        return response()->json(['url' => $url]);
    }

    /**
     * A link into Stripe's customer portal.
     */
    public function portal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'return_url' => ['sometimes', 'url'],
        ]);

        $url = $this->billing->portalUrl(
            $this->tenancy->organization(),
            $data['return_url'] ?? $this->defaultUrl('/billing'),
        );

        return response()->json(['url' => $url]);
    }

    protected function defaultUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
