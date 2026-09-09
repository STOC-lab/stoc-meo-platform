<?php

namespace App\Enums;

/**
 * Whether a stored Google token can still be used.
 *
 * Expired means the refresh failed or the access token ran out and could not
 * be renewed — the connection is recoverable by reconnecting. Revoked means
 * Google told us the grant is gone, so reconnecting is the only way back.
 */
enum GbpTokenStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * Whether the connection is worth attempting a call with.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether someone has to reconnect the account before it works again.
     */
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
