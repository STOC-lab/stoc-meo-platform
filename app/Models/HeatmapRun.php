<?php

namespace App\Models;

use App\Enums\HeatmapGridSize;
use App\Enums\HeatmapRunStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One heatmap: a keyword checked from every point of a grid laid over the
 * store front's neighbourhood.
 *
 * The row is created when the run is asked for and only ever moves forward
 * through its statuses, so it carries created_at alone and marks the end with
 * completed_at.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'keyword_id',
    'grid_size',
    'status',
    'scheduled_at',
    'completed_at',
    'failure_reason',
])]
class HeatmapRun extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grid_size' => HeatmapGridSize::class,
            'status' => HeatmapRunStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Keyword, $this>
     */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /**
     * @return HasMany<HeatmapPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(HeatmapPoint::class);
    }

    /**
     * @param  Builder<HeatmapRun>  $query
     */
    public function scopeStatus(Builder $query, HeatmapRunStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Claim the run for a worker, answering whether this call is the one that
     * took it.
     *
     * The update is conditional on the run still being pending, so a retry of
     * the job — or a second worker — finds nothing to claim and does not
     * charge the organization's allowance twice for the same grid.
     */
    public function claim(): bool
    {
        $claimed = static::acrossTenants()
            ->whereKey($this->getKey())
            ->where('status', HeatmapRunStatus::Pending)
            ->update(['status' => HeatmapRunStatus::Running]);

        if ($claimed === 0) {
            return false;
        }

        $this->setAttribute('status', HeatmapRunStatus::Running);

        return true;
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => HeatmapRunStatus::Completed,
            'completed_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => HeatmapRunStatus::Failed,
            'completed_at' => now(),
            // The column is narrower than an exception message can be.
            'failure_reason' => mb_substr($reason, 0, 255),
        ])->save();
    }
}
