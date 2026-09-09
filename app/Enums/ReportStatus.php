<?php

namespace App\Enums;

/**
 * Where a monthly report has got to. A report is queued as Pending, moves to
 * Generating when a worker picks it up, and ends as Completed or Failed.
 */
enum ReportStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether the report has stopped moving, either way.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    /**
     * Whether there is a file to hand out.
     */
    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待機中',
            self::Generating => '作成中',
            self::Completed => '完了',
            self::Failed => '失敗',
        };
    }
}
