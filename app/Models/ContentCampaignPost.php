<?php

namespace App\Models;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One campaign's post for one channel.
 *
 * Each row carries its own status and its own error because each channel is
 * published by its own job: Instagram failing must leave the Business Profile
 * post exactly as it was.
 */
#[Fillable([
    'campaign_id',
    'organization_id',
    'channel',
    'status',
    'ai_prompt',
    'ai_content',
    'ai_hashtags',
    'platform_post_id',
    'published_at',
    'retry_count',
    'max_retries',
    'last_error',
    'idempotency_key',
    'approved_by_user_id',
    'approved_at',
])]
class ContentCampaignPost extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CampaignChannel::class,
            'status' => CampaignPostStatus::class,
            'ai_hashtags' => 'array',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ContentCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ContentCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @param  Builder<ContentCampaignPost>  $query
     */
    public function scopeAwaitingApproval(Builder $query): void
    {
        $query->where('status', CampaignPostStatus::AwaitingApproval);
    }

    /**
     * Move the post from one status to another only if it is still where the
     * caller thought it was. Answering false means somebody else got there
     * first, which is what stops a retry from doing the work twice.
     */
    public function transition(CampaignPostStatus $from, CampaignPostStatus $to): bool
    {
        $moved = static::acrossTenants()
            ->whereKey($this->getKey())
            ->where('status', $from)
            ->update(['status' => $to, 'updated_at' => now()]);

        if ($moved === 0) {
            return false;
        }

        $this->setAttribute('status', $to);

        return true;
    }

    /**
     * Whether another publishing attempt is allowed.
     */
    public function hasRetriesLeft(): bool
    {
        return $this->retry_count < $this->max_retries;
    }

    public function markPublished(string $platformPostId): void
    {
        $this->forceFill([
            'status' => CampaignPostStatus::Published,
            'platform_post_id' => $platformPostId,
            'published_at' => now(),
            'last_error' => null,
        ])->save();

        $this->campaign?->completeIfSettled();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => CampaignPostStatus::Failed,
            'last_error' => mb_substr($reason, 0, 1000),
        ])->save();

        $this->campaign?->completeIfSettled();
    }

    /**
     * Record a failed attempt that will be tried again.
     */
    public function recordAttempt(string $reason): void
    {
        $this->forceFill([
            'retry_count' => $this->retry_count + 1,
            'last_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }
}
