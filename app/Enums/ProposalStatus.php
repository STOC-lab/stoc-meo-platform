<?php

namespace App\Enums;

/**
 * Where a proposal stands with the person it was written for. New is unread,
 * InProgress is being worked on, Done is finished, and Dismissed is one they
 * decided against — kept rather than deleted so the same suggestion is not
 * made again as though it were new.
 */
enum ProposalStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Dismissed = 'dismissed';

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::InProgress], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::New => '未対応',
            self::InProgress => '対応中',
            self::Done => '対応済み',
            self::Dismissed => '見送り',
        };
    }
}
