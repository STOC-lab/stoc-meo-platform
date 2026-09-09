<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's MEO score for one store front, with the working behind it.
 */
#[Fillable(['organization_id', 'location_id', 'score', 'breakdown', 'calculated_at'])]
class MeoScore extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'breakdown' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The parts of the score that could actually be measured, weakest first —
     * which is where an improvement proposal should start.
     *
     * @return array<string, array<string, mixed>>
     */
    public function weakestComponents(): array
    {
        $measured = array_filter(
            $this->breakdown ?? [],
            fn ($component) => ($component['measured'] ?? false) === true,
        );

        uasort($measured, fn ($a, $b) => ($a['score'] ?? 0) <=> ($b['score'] ?? 0));

        return $measured;
    }
}
