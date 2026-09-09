<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Staff->value,
            // Only the hash is stored; use plaintext() when the link matters.
            'token' => Invitation::hashToken(fake()->unique()->sha256()),
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
            'accepted_at' => null,
        ];
    }

    /**
     * Store the hash of a known plaintext token, so a test can follow the link.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn () => ['token' => Invitation::hashToken($plainToken)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }
}
