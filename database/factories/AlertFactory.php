<?php

namespace Database\Factories;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Keyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

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
            'type' => AlertType::RankDrop,
            'payload' => ['previous_rank' => 3, 'current_rank' => 12, 'drop' => 9],
            'is_read' => false,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['is_read' => true]);
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
