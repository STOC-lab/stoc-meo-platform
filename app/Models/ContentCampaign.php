<?php

namespace App\Models;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One theme turned into posts across several channels.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'name',
    'theme',
    'source_image_path',
    'campaign_type',
    'scheduled_at',
    'status',
    'created_by_user_id',
])]
class ContentCampaign extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'campaign_type' => CampaignType::class,
            'status' => CampaignStatus::class,
            'scheduled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<ContentCampaignPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ContentCampaignPost::class, 'campaign_id');
    }

    /**
     * @param  Builder<ContentCampaign>  $query
     */
    public function scopeRunnable(Builder $query): void
    {
        $query->whereIn('status', [CampaignStatus::Draft, CampaignStatus::Active]);
    }

    /**
     * Add the channels this campaign has not got a post for yet, leaving the
     * ones it already has alone.
     *
     * @param  array<int, CampaignChannel>  $channels
     * @return array<int, ContentCampaignPost>
     */
    public function addChannels(array $channels): array
    {
        $existing = $this->posts()->pluck('channel')->all();
        $added = [];

        foreach ($channels as $channel) {
            if (in_array($channel->value, array_map(
                fn ($value) => $value instanceof CampaignChannel ? $value->value : $value,
                $existing,
            ), true)) {
                continue;
            }

            $added[] = $this->posts()->create([
                'organization_id' => $this->organization_id,
                'channel' => $channel,
                'status' => CampaignPostStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
            ]);
        }

        return $added;
    }

    /**
     * Close the campaign once every one of its posts has settled, so a
     * finished campaign does not sit forever as active.
     */
    public function completeIfSettled(): void
    {
        if ($this->status->isFinished()) {
            return;
        }

        $unsettled = $this->posts()
            ->whereNotIn('status', [
                CampaignPostStatus::Published,
                CampaignPostStatus::Failed,
                CampaignPostStatus::Cancelled,
            ])
            ->exists();

        if (! $unsettled) {
            $this->forceFill(['status' => CampaignStatus::Completed])->save();
        }
    }
}
