<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;

/**
 * Wraps the Stripe calls Cashier makes on an organization's behalf.
 *
 * Keeping them behind one class gives the HTTP layer a seam it can be tested
 * against without reaching Stripe.
 */
class BillingService
{
    /**
     * Start a Checkout session for the plan and return the URL to send the
     * customer to. The organization and plan travel in the metadata so the
     * webhook can attribute the result even before the subscription lands.
     *
     * They are attached twice on purpose, because the two calls put them in
     * different places. `withMetadata()` is Cashier's, and it writes
     * `subscription_data.metadata` — which reaches the `customer.subscription.*`
     * events and nothing else. The session's own `metadata` is what
     * `checkout.session.completed` carries, and only a session option puts
     * anything there.
     *
     * Sending only the first leaves `session.metadata` empty, and the webhook
     * then falls back to the Stripe customer for the organization and cannot
     * tell which plan was bought at all.
     */
    public function checkoutUrl(Organization $organization, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        $metadata = [
            'organization_id' => (string) $organization->getKey(),
            'plan_id' => (string) $plan->getKey(),
        ];

        return $organization->newSubscription('default', $plan->stripe_price_id)
            ->withMetadata($metadata)
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ])
            ->url;
    }

    /**
     * A link to Stripe's customer portal, where the organization manages its
     * payment method, invoices and cancellation.
     */
    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        $organization->createOrGetStripeCustomer();

        return $organization->billingPortalUrl($returnUrl);
    }
}
