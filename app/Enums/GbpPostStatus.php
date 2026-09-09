<?php

namespace App\Enums;

/**
 * Where a Google Business Profile post has got to. A post is stored as Draft
 * until it is sent, moves to Publishing while the call is in flight, and ends
 * as Published or Failed.
 */
enum GbpPostStatus: string
{
    case Draft = 'draft';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Published, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Publishing => '投稿中',
            self::Published => '公開済み',
            self::Failed => '失敗',
        };
    }
}
