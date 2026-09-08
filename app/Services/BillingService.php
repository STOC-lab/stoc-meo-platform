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
     * customer to. The organization and plan travel in the session metadata so
     * the webhook can attribute the result even before the subscription lands.
     */
    public function checkoutUrl(Organization $organization, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        return $organization->newSubscription('default', $plan->stripe_price_id)
            ->withMetadata([
                'organization_id' => (string) $organization->getKey(),
                'plan_id' => (string) $plan->getKey(),
            ])
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
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
