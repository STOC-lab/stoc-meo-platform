<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review left on a store front's Google Business Profile, copied here so it
 * can be listed and reported on without calling Google every time.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'google_review_id',
    'author_name',
    'author_photo_url',
    'rating',
    'comment',
    'reply',
    'replied_at',
    'reviewed_at',
])]
class Review extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'replied_at' => 'datetime',
            'reviewed_at' => 'datetime',
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
     * @param  Builder<Review>  $query
     */
    public function scopeUnanswered(Builder $query): void
    {
        $query->whereNull('replied_at');
    }

    public function isAnswered(): bool
    {
        return $this->replied_at !== null;
    }
}
