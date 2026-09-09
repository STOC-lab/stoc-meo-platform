<?php

namespace App\Enums;

/**
 * Whether a stored Instagram token can still be used.
 *
 * The same three states as a Business Profile connection, kept separate
 * because the two modules' vocabularies are their own: Instagram's long-lived
 * tokens expire on a sixty-day clock rather than hourly, and what counts as
 * revoked is Meta's decision rather than Google's.
 */
enum InstagramTokenStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    public function needsReconnection(): bool
    {
        return $this !== self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => '接続中',
            self::Expired => '期限切れ',
            self::Revoked => '連携解除',
        };
    }
}
