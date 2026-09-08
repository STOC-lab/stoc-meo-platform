<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Http\Response;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

/**
 * Handles the Stripe events that move an organization between billing states.
 *
 * Cashier's own handlers keep the subscriptions table in step; this controller
 * adds the organization-level bookkeeping the design document asks for —
 * status, plan_id and the denormalised stripe_subscription_id.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * A finished Checkout session: attach the customer to the organization and
     * activate the plan it paid for.
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        $session = $payload['data']['object'];
        $organization = $this->resolveOrganization($session);

        if ($organization === null) {
            return $this->ok();
        }

        if (blank($organization->stripe_id) && filled($session['customer'] ?? null)) {
            $organization->stripe_id = $session['customer'];
        }

        $organization->forceFill([
            'stripe_subscription_id' => $session['subscription'] ?? $organization->stripe_subscription_id,
            'plan_id' => $this->planFromMetadata($session) ?? $organization->plan_id,
            'status' => Organization::STATUS_ACTIVE,
        ])->save();

        return $this->ok();
    }

    /**
     * A paid invoice clears a past-due organization.
     */
    protected function handleInvoicePaid(array $payload): Response
    {
        $organization = $this->organizationFor($payload['data']['object']['customer'] ?? null);

        if ($organization && $organization->status === Organization::STATUS_PAST_DUE) {
            $organization->forceFill(['status' => Organization::STATUS_ACTIVE])->save();
        }

        return $this->ok();
    }

    /**
     * A failed payment holds the organization until Stripe retries.
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $organization = $this->organizationFor($payload['data']['object']['customer'] ?? null);

        if ($organization && $organization->status !== Organization::STATUS_CANCELED) {
            $organization->forceFill(['status' => Organization::STATUS_PAST_DUE])->save();
        }

        return $this->ok();
    }

    /**
     * Plan or status changes: let Cashier update the subscription row first,
     * then mirror the outcome onto the organization.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        parent::handleCustomerSubscriptionUpdated($payload);

        $subscription = $payload['data']['object'];
        $organization = $this->organizationFor($subscription['customer'] ?? null);

        if ($organization !== null) {
            $organization->forceFill([
                'stripe_subscription_id' => $subscription['id'],
                'plan_id' => $this->planFromSubscription($subscription) ?? $organization->plan_id,
                'status' => $this->statusFor($subscription['status'] ?? null, $organization),
            ])->save();
        }

        return $this->ok();
    }

    /**
     * A cancelled subscription drops the organization back to the free plan,
     * since entitlements are read from the plan rather than from status.
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        parent::handleCustomerSubscriptionDeleted($payload);

        $subscription = $payload['data']['object'];
        $organization = $this->organizationFor($subscription['customer'] ?? null);

        if ($organization !== null) {
            $organization->forceFill([
                'stripe_subscription_id' => null,
                'plan_id' => Plan::where('code', 'meo_free')->value('id') ?? $organization->plan_id,
                'status' => Organization::STATUS_CANCELED,
            ])->save();
        }

        return $this->ok();
    }

    /**
     * Checkout carries the organization in its metadata; fall back to the
     * Stripe customer for sessions created outside the application.
     *
     * @param  array<string, mixed>  $session
     */
    protected function resolveOrganization(array $session): ?Organization
    {
        $organizationId = $session['metadata']['organization_id'] ?? null;

        if ($organizationId !== null) {
            $organization = Organization::find($organizationId);

            if ($organization !== null) {
                return $organization;
            }
        }

        return $this->organizationFor($session['customer'] ?? null);
    }

    protected function organizationFor(?string $stripeId): ?Organization
    {
        if (blank($stripeId)) {
            return null;
        }

        return Organization::where('stripe_id', $stripeId)->first();
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function planFromMetadata(array $session): ?int
    {
        $planId = $session['metadata']['plan_id'] ?? null;

        return $planId === null ? null : Plan::whereKey($planId)->value('id');
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    protected function planFromSubscription(array $subscription): ?int
    {
        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;

        return $priceId === null ? null : Plan::where('stripe_price_id', $priceId)->value('id');
    }

    protected function statusFor(?string $stripeStatus, Organization $organization): string
    {
        return match ($stripeStatus) {
            'active' => Organization::STATUS_ACTIVE,
            'trialing' => Organization::STATUS_TRIALING,
            'past_due', 'unpaid', 'incomplete' => Organization::STATUS_PAST_DUE,
            'canceled', 'incomplete_expired' => Organization::STATUS_CANCELED,
            default => $organization->status,
        };
    }

    protected function ok(): Response
    {
        return new Response('Webhook Handled', 200);
    }
}
