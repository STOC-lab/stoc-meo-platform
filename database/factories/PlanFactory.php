<?php

namespace Database\Factories;

use App\Enums\Feature;
use App\Models\Plan;
use App\Services\FeatureResolver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tier = fake()->randomElement([Plan::TIER_LIGHT, Plan::TIER_STANDARD, Plan::TIER_PREMIUM]);

        return [
            'code' => fake()->unique()->slug(2),
            'name' => 'Plan '.fake()->unique()->word(),
            'product' => Plan::PRODUCT_MEO,
            'tier' => $tier,
            'price' => fake()->randomElement([9800, 29800, 79800]),
            'currency' => 'JPY',
            'interval' => Plan::INTERVAL_MONTH,
            'billing_period_months' => 1,
            'phases' => 1,
            'trial_days' => 14,
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    /**
     * A plan sold on a multi-year term: billed once a year, for as many years
     * as the commitment runs. `price` is the yearly charge, not the total.
     */
    public function term(int $years): static
    {
        return $this->state(fn () => [
            'interval' => Plan::INTERVAL_YEAR,
            'billing_period_months' => $years * 12,
            'phases' => $years,
        ]);
    }

    /**
     * A plan bought outright for a fixed term, with no Stripe recurring price
     * behind it. The 6-month MEO PREMIUM special is the only one.
     */
    public function oneTime(int $months): static
    {
        return $this->state(fn () => [
            'interval' => Plan::INTERVAL_ONE_TIME,
            'billing_period_months' => $months,
            'phases' => 1,
        ]);
    }

    /**
     * Attach features to the plan. Null values mean unlimited.
     *
     * @param  array<string, int|bool|string|null>  $features
     */
    public function withFeatures(array $features): static
    {
        return $this->afterCreating(function (Plan $plan) use ($features) {
            foreach ($features as $key => $value) {
                $key = $key instanceof Feature ? $key->value : $key;

                $plan->features()->create([
                    'key' => $key,
                    'type' => FeatureResolver::typeFor($key),
                    'value' => match (true) {
                        $value === null => null,
                        is_bool($value) => $value ? '1' : '0',
                        default => (string) $value,
                    },
                ]);
            }

            app(FeatureResolver::class)->flush($plan);
        });
    }
}
