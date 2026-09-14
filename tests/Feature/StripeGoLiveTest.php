<?php

namespace Tests\Feature;

use App\Console\Commands\PrepareStripeGoLive;
use App\Http\Controllers\StripeWebhookController;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The go-live runbook is printed from the database rather than written down,
 * so what it prints is worth holding to what the application actually does.
 */
class StripeGoLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $code, string $name, int $price): Plan
    {
        return Plan::factory()->create([
            'code' => $code,
            'name' => $name,
            'price' => $price,
            'currency' => 'JPY',
            'interval' => 'month',
            'stripe_price_id' => 'price_test_'.$code,
        ]);
    }

    /**
     * The method Cashier would dispatch a given event to.
     */
    protected function handlerFor(string $event): string
    {
        return 'handle'.Str::studly(str_replace('.', '_', $event));
    }

    public function test_every_event_the_endpoint_is_told_to_subscribe_to_has_a_handler(): void
    {
        foreach (PrepareStripeGoLive::WEBHOOK_EVENTS as $event) {
            $method = $this->handlerFor($event);

            $this->assertTrue(
                method_exists(StripeWebhookController::class, $method),
                "The runbook subscribes the endpoint to [{$event}], but nothing answers {$method}().",
            );
        }
    }

    public function test_every_handler_this_application_adds_is_an_event_the_endpoint_subscribes_to(): void
    {
        $handlers = collect((new ReflectionClass(StripeWebhookController::class))->getMethods())
            ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === StripeWebhookController::class)
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'handle') && $name !== 'handleWebhook');

        $subscribed = collect(PrepareStripeGoLive::WEBHOOK_EVENTS)
            ->map(fn (string $event) => $this->handlerFor($event));

        // A handler with nobody sending it the event is a subscription that is
        // paid for and never activates, which is the failure this catches.
        $this->assertSame([], $handlers->diff($subscribed)->values()->all());
        $this->assertCount($handlers->count(), $subscribed);
    }

    public function test_the_sql_repoints_every_plan_by_name(): void
    {
        $this->plan('meo_light', 'MEO LIGHT', 9000);
        $this->plan('ig_premium', 'IG PREMIUM', 15000);

        $this->artisan('stripe:go-live', [
            '--price' => ['meo_light=price_1Live001', 'ig_premium=price_1Live002'],
        ])
            ->expectsOutputToContain("UPDATE plans SET stripe_price_id = 'price_1Live001', updated_at = NOW() WHERE name = 'MEO LIGHT';")
            ->expectsOutputToContain("UPDATE plans SET stripe_price_id = 'price_1Live002', updated_at = NOW() WHERE name = 'IG PREMIUM';")
            ->assertSuccessful();
    }

    public function test_a_plan_still_without_a_live_price_is_named_rather_than_quietly_left_out(): void
    {
        $this->plan('meo_light', 'MEO LIGHT', 9000);
        $this->plan('meo_premium', 'MEO PREMIUM', 30000);

        $this->artisan('stripe:go-live', ['--price' => ['meo_light=price_1Live001']])
            ->expectsOutputToContain('price_REPLACE_MEO_PREMIUM')
            ->expectsOutputToContain('Still waiting on a live price id for: meo_premium')
            ->assertSuccessful();
    }

    public function test_a_price_id_for_a_plan_that_does_not_exist_is_refused(): void
    {
        $this->plan('meo_light', 'MEO LIGHT', 9000);

        $this->artisan('stripe:go-live', ['--price' => ['meo_lite=price_1Live001']])
            ->expectsOutputToContain('there is no plan with the code [meo_lite]')
            ->assertSuccessful();
    }

    public function test_something_that_is_not_a_price_id_is_refused(): void
    {
        $this->plan('meo_light', 'MEO LIGHT', 9000);

        // A product id pasted where a price id belongs is the mistake this
        // catches, and it is one Stripe would not report until checkout.
        $this->artisan('stripe:go-live', ['--price' => ['meo_light=prod_1Live001']])
            ->expectsOutputToContain('a price id starts with price_')
            ->doesntExpectOutputToContain('prod_1Live001')
            ->assertSuccessful();
    }

    public function test_the_price_commands_carry_the_catalogue_figures(): void
    {
        $this->plan('meo_premium', 'MEO PREMIUM', 30000);

        $this->artisan('stripe:go-live')
            ->expectsOutputToContain('--unit-amount=30000')
            ->expectsOutputToContain('--currency=jpy')
            ->expectsOutputToContain('--recurring[interval]=month')
            ->expectsOutputToContain('--lookup-key=meo_premium')
            ->assertSuccessful();
    }

    public function test_the_webhook_endpoint_is_this_applications_url_and_every_event(): void
    {
        $this->plan('meo_light', 'MEO LIGHT', 9000);

        config(['app.url' => 'https://meo.example.test/']);

        $command = $this->artisan('stripe:go-live')
            ->expectsOutputToContain('--url=https://meo.example.test/stripe/webhook');

        foreach (PrepareStripeGoLive::WEBHOOK_EVENTS as $event) {
            $command->expectsOutputToContain("--enabled-events={$event}");
        }

        $command->assertSuccessful();
    }

    public function test_the_test_mode_billing_links_that_have_to_go_are_named(): void
    {
        $this->plan('meo_light', 'MEO LIGHT', 9000);

        $organization = Organization::factory()->create(['stripe_id' => 'cus_TestOnly']);

        $this->artisan('stripe:go-live')
            ->expectsOutputToContain("-- {$organization->name} (id {$organization->id}) currently cus_TestOnly")
            ->expectsOutputToContain('UPDATE organizations SET stripe_id = NULL')
            ->assertSuccessful();
    }

    public function test_nothing_is_written_by_printing_the_runbook(): void
    {
        $plan = $this->plan('meo_light', 'MEO LIGHT', 9000);
        $organization = Organization::factory()->create(['stripe_id' => 'cus_TestOnly']);

        $this->artisan('stripe:go-live', ['--price' => ['meo_light=price_1Live001']])->assertSuccessful();

        $this->assertSame('price_test_meo_light', $plan->fresh()->stripe_price_id);
        $this->assertSame('cus_TestOnly', $organization->fresh()->stripe_id);
    }

    public function test_an_empty_catalogue_fails_rather_than_printing_a_switch_with_no_prices(): void
    {
        $this->artisan('stripe:go-live')
            ->expectsOutputToContain('The plan catalogue is empty.')
            ->assertFailed();
    }
}
