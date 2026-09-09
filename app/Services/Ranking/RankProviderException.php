<?php

namespace App\Services\Ranking;

use RuntimeException;

/**
 * A provider could not produce an answer. The router treats this as a reason
 * to move on to the next provider rather than as a failed check.
 */
class RankProviderException extends RuntimeException
{
    public static function for(string $provider, string $reason): self
    {
        return new self("Rank provider [{$provider}] failed: {$reason}");
    }
}
