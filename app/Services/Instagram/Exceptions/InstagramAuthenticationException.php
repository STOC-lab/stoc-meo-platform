<?php

namespace App\Services\Instagram\Exceptions;

/**
 * Meta refused the connection's token. Retrying cannot fix it — the account
 * has to be reconnected — so callers should stop rather than spend attempts.
 */
class InstagramAuthenticationException extends InstagramException
{
    public static function because(string $reason): self
    {
        return new self("Instagram rejected the connection: {$reason}");
    }
}
