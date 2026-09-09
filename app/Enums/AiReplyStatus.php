<?php

namespace App\Enums;

/**
 * Where a review's AI-written reply has got to.
 *
 * A reply is never sent to Google straight from the model: it waits for a
 * person unless the plan turns that off, which is what review.auto_reply.enabled
 * decides.
 */
enum AiReplyStatus: string
{
    case Generating = 'generating';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Published = 'published';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Published, self::Failed], true);
    }

    public function needsApproval(): bool
    {
        return $this === self::AwaitingApproval;
    }

    public function label(): string
    {
        return match ($this) {
            self::Generating => '生成中',
            self::AwaitingApproval => '承認待ち',
            self::Approved => '承認済み',
            self::Published => '投稿済み',
            self::Failed => '失敗',
        };
    }
}
