<?php

namespace App\Models;

use App\Enums\GbpPostStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post published to a store front's Google Business Profile.
 *
 * The row exists before the call to Google does, so a post that fails to
 * publish is visible with its reason rather than lost.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'content',
    'media_url',
    'cta_type',
    'cta_url',
    'status',
    'published_at',
    'gbp_post_id',
    'failure_reason',
])]
class GbpPost extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GbpPostStatus::class,
            'published_at' => 'datetime',
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
     * Claim the post for a worker, answering whether this call is the one that
     * took it. Conditional on the post still being a draft, so a retry does
     * not publish the same thing to Google twice.
     */
    public function claim(): bool
    {
        $claimed = static::acrossTenants()
            ->whereKey($this->getKey())
            ->where('status', GbpPostStatus::Draft)
            ->update(['status' => GbpPostStatus::Publishing]);

        if ($claimed === 0) {
            return false;
        }

        $this->setAttribute('status', GbpPostStatus::Publishing);

        return true;
    }

    public function markPublished(string $gbpPostId): void
    {
        $this->forceFill([
            'status' => GbpPostStatus::Published,
            'gbp_post_id' => $gbpPostId,
            'published_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => GbpPostStatus::Failed,
            // The column is narrower than an exception message can be.
            'failure_reason' => mb_substr($reason, 0, 255),
        ])->save();
    }
}
