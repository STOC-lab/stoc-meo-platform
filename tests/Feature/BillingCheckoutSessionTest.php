<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * What BillingService actually sends to Stripe.
 *
 * Cashier builds the Checkout payload, so the only way to know where the
 * organization and plan ended up is to read the request. Stripe's transport is
 * swapped for a recorder rather than faked at a higher level; nothing here
 * leaves the process.
 */
class BillingCheckoutSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, array{url: string, params: array<string, mixed>}>
     */
    protected array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_recorder']);

        ApiRequestor::setHttpClient($this->recordingStripeClient());
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /**
     * A Stripe transport that records what it was asked for and answers with
     * the shape the SDK expects.
     */
    protected function recordingStripeClient(): ClientInterface
    {
        return new class($this->requests) implements ClientInterface
        {
            /**
             * @param  array<int, array{url: string, params: array<string, mixed>}>  $requests
             */
            public function __construct(protected array &$requests) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['url' => $absUrl, 'params' => $params];

                $body = match (true) {
                    str_contains($absUrl, '/v1/checkout/sessions') => [
                        'id' => 'cs_test_recorded',
                        'object' => 'checkout.session',
                        'url' => 'https://checkout.stripe.com/c/pay/cs_test_recorded',
                    ],
                    str_contains($absUrl, '/v1/customers') => [
                        'id' => 'cus_test_recorded',
                        'object' => 'customer',
                    ],
                    default => ['object' => 'unknown'],
                };

                return [json_encode($body), 200, []];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkoutRequest(): array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], '/v1/checkout/sessions')) {
                return $request['params'];
            }
        }

        $this->fail('No Checkout session was created.');
    }

    public function test_the_checkout_session_carries_the_organization_and_plan_in_its_own_metadata(): void
    {
        $organization = Organization::factory()->create(['stripe_id' => 'cus_test_recorded']);
        $plan = Plan::factory()->create(['code' => 'meo_light', 'stripe_price_id' => 'price_light']);

        $url = app(BillingService::class)->checkoutUrl(
            $organization,
            $plan,
            'https://app.example.com/ok',
            'https://app.example.com/no',
        );

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_recorded', $url);

        $params = $this->checkoutRequest();

        // The session's own metadata is what checkout.session.completed
        // carries. Cashier's withMetadata() does not reach it.
        $this->assertSame((string) $organization->id, $params['metadata']['organization_id']);
        $this->assertSame((string) $plan->id, $params['metadata']['plan_id']);
    }

    public function test_the_subscription_metadata_is_sent_as_well(): void
    {
        $organization = Organization::factory()->create(['stripe_id' => 'cus_test_recorded']);
        $plan = Plan::factory()->create(['code' => 'meo_light', 'stripe_price_id' => 'price_light']);

        app(BillingService::class)->checkoutUrl(
            $organization,
            $plan,
            'https://app.example.com/ok',
            'https://app.example.com/no',
        );

        $params = $this->checkoutRequest();

        // This half reaches the customer.subscription.* events.
        $this->assertSame((string) $organization->id, $params['subscription_data']['metadata']['organization_id']);
        $this->assertSame((string) $plan->id, $params['subscription_data']['metadata']['plan_id']);
        $this->assertSame('price_light', $params['line_items'][0]['price']);
    }
}
