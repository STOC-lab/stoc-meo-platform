<?php

namespace App\Enums;

/**
 * A campaign post's journey, as defined by design v1.3 §22: it waits, is
 * written by the model, waits for a person, is approved, is published — and
 * can fail or be called off at any point along the way.
 */
enum CampaignPostStatus: string
{
    case Pending = 'pending';
    case AiGenerating = 'ai_generating';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Published, self::Failed, self::Cancelled], true);
    }

    /**
     * Whether the post is waiting on a person rather than on the system.
     */
    public function needsApproval(): bool
    {
        return $this === self::AwaitingApproval;
    }

    /**
     * Whether the post is ready to be handed to its channel.
     */
    public function isPublishable(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待機中',
            self::AiGenerating => 'AI生成中',
            self::AwaitingApproval => '承認待ち',
            self::Approved => '承認済み',
            self::Publishing => '投稿中',
            self::Published => '公開済み',
            self::Failed => '失敗',
            self::Cancelled => '中止',
        };
    }
}
