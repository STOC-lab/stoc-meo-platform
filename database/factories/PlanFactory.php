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
            'interval' => 'month',
            'trial_days' => 14,
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
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
