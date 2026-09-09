<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the organization should look at. Raised by the checks that run
 * behind the daily sweep and cleared by a person reading it, so the row only
 * ever changes through is_read and carries created_at alone.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'keyword_id',
    'type',
    'payload',
    'is_read',
])]
class Alert extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'payload' => 'array',
            'is_read' => 'boolean',
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
     * @param  Builder<Alert>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->where('is_read', false);
    }

    /**
     * @param  Builder<Alert>  $query
     */
    public function scopeOfType(Builder $query, AlertType $type): void
    {
        $query->where('type', $type);
    }
}
