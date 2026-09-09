<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grid point of one heatmap. Written once when the run finishes the point
 * and never updated, so the row carries created_at alone.
 */
#[Fillable([
    'heatmap_run_id',
    'organization_id',
    'lat',
    'lng',
    'rank',
    'row',
    'col',
])]
class HeatmapPoint extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'rank' => 'integer',
            'row' => 'integer',
            'col' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HeatmapRun, $this>
     */
    public function heatmapRun(): BelongsTo
    {
        return $this->belongsTo(HeatmapRun::class);
    }

    /**
     * Whether the store front appeared in the results seen from this point.
     */
    public function isRanked(): bool
    {
        return $this->rank !== null;
    }
}
