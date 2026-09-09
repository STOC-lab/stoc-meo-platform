<?php

namespace App\Models;

use App\Enums\AnalysisType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-written analysis of a store front over a period.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'type',
    'content',
    'period_start',
    'period_end',
    'model',
])]
class Analysis extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AnalysisType::class,
            'content' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
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
     * @param  Builder<Analysis>  $query
     */
    public function scopeOfType(Builder $query, AnalysisType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * The headline the UI leads with.
     */
    public function summary(): ?string
    {
        return $this->content['summary'] ?? null;
    }
}
