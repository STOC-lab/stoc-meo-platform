<?php

namespace Database\Factories;

use App\Models\Keyword;
use App\Models\RankingResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RankingResult>
 */
class RankingResultFactory extends Factory
{
    protected $model = RankingResult::class;

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
            'rank' => fake()->numberBetween(1, 20),
            'search_url' => fake()->url(),
            'checked_at' => now(),
            'provider' => 'dataforseo',
        ];
    }

    /**
     * The store front did not appear in the results.
     */
    public function unranked(): static
    {
        return $this->state(fn () => ['rank' => null]);
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
