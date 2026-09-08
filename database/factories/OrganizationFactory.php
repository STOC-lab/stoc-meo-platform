<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'status' => Organization::STATUS_ACTIVE,
            'plan_id' => null,
        ];
    }

    public function onPlan(Plan|string $plan): static
    {
        return $this->state(fn () => [
            'plan_id' => $plan instanceof Plan
                ? $plan->getKey()
                : Plan::where('code', $plan)->value('id'),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Organization::STATUS_SUSPENDED]);
    }
}
