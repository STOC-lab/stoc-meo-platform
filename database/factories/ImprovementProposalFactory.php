<?php

namespace Database\Factories;

use App\Enums\ProposalCategory;
use App\Enums\ProposalPriority;
use App\Enums\ProposalStatus;
use App\Models\ImprovementProposal;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImprovementProposal>
 */
class ImprovementProposalFactory extends Factory
{
    protected $model = ImprovementProposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $location = Location::factory();

        return [
            'location_id' => $location,
            'organization_id' => fn (array $attributes) => Location::acrossTenants()
                ->whereKey($attributes['location_id'])
                ->value('organization_id'),
            'category' => ProposalCategory::Reviews,
            'title' => '口コミへの返信率を上げる',
            'content' => '未返信の口コミが残っています。返信は検索順位にも影響します。',
            'priority' => ProposalPriority::Medium,
            'status' => ProposalStatus::New,
            'score_at_generation' => 62.5,
        ];
    }

    public function priority(ProposalPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function status(ProposalStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function forLocation(Location $location): static
    {
        return $this->state(fn () => [
            'location_id' => $location->getKey(),
            'organization_id' => $location->organization_id,
        ]);
    }
}
