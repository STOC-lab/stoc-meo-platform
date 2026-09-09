<?php

namespace App\Models;

use App\Enums\ProposalCategory;
use App\Enums\ProposalPriority;
use App\Enums\ProposalStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a model suggested the store front do.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'category',
    'title',
    'content',
    'priority',
    'status',
    'score_at_generation',
])]
class ImprovementProposal extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ProposalCategory::class,
            'priority' => ProposalPriority::class,
            'status' => ProposalStatus::class,
            'score_at_generation' => 'float',
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
     * @param  Builder<ImprovementProposal>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ProposalStatus::New, ProposalStatus::InProgress]);
    }
}
