<?php

namespace App\Services\Ranking;

use RuntimeException;

/**
 * A provider could not produce an answer.
 *
 * Whether that is worth asking again decides what the router does with it. A
 * transient failure — a throttle, a timeout, an outage at the far end — says
 * nothing about the keyword and would be answered differently a minute later,
 * so a caller that can retry is given the chance to. A permanent one is the
 * provider's settled answer, and the router moves on to the next provider.
 */
class RankProviderException extends RuntimeException
{
    public function __construct(string $message, protected bool $transient = false)
    {
        parent::__construct($message);
    }

    public static function for(string $provider, string $reason, bool $transient = false): self
    {
        return new self("Rank provider [{$provider}] failed: {$reason}", $transient);
    }

    /**
     * A failure that describes the moment rather than the request.
     */
    public static function transient(string $provider, string $reason): self
    {
        return self::for($provider, $reason, transient: true);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
