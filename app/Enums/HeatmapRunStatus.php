<?php

namespace App\Enums;

/**
 * Where a heatmap run has got to. A run is queued as Pending, moves to Running
 * when a worker picks it up, and ends as Completed or Failed.
 */
enum HeatmapRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether the run has stopped moving, either way.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待機中',
            self::Running => '取得中',
            self::Completed => '完了',
            self::Failed => '失敗',
        };
    }
}
