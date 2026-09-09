<?php

namespace Database\Factories;

use App\Enums\HeatmapGridSize;
use App\Enums\HeatmapRunStatus;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeatmapRun>
 */
class HeatmapRunFactory extends Factory
{
    protected $model = HeatmapRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keyword = Keyword::factory();

        return [
            'keyword_id' => $keyword,
            'location_id' => fn (array $attributes) => Keyword::acrossTenants()
                ->whereKey($attributes['keyword_id'])
                ->value('location_id'),
            'organization_id' => fn (array $attributes) => Keyword::acrossTenants()
                ->whereKey($attributes['keyword_id'])
                ->value('organization_id'),
            'grid_size' => HeatmapGridSize::Grid5x5,
            'status' => HeatmapRunStatus::Pending,
            'scheduled_at' => now(),
            'completed_at' => null,
            'failure_reason' => null,
        ];
    }

    public function gridSize(HeatmapGridSize $size): static
    {
        return $this->state(fn () => ['grid_size' => $size]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => HeatmapRunStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'provider unavailable'): static
    {
        return $this->state(fn () => [
            'status' => HeatmapRunStatus::Failed,
            'completed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    public function forKeyword(Keyword $keyword): static
    {
        return $this->state(fn () => [
            'keyword_id' => $keyword->getKey(),
            'location_id' => $keyword->location_id,
            'organization_id' => $keyword->organization_id,
        ]);
    }
}
