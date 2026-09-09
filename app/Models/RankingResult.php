<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rank observation for a keyword. Rows are written once and never
 * updated — a correction is a new check — so the table carries created_at
 * alone, and lives in the month partition its checked_at falls in.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'keyword_id',
    'rank',
    'search_url',
    'checked_at',
    'provider',
])]
class RankingResult extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Keyword, $this>
     */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Whether the store front appeared in the results at all.
     */
    public function isRanked(): bool
    {
        return $this->rank !== null;
    }
}
