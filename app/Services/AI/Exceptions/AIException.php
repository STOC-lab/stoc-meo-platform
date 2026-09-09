<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * A model could not produce an answer. Retrying may help; it is not something
 * the person on the other end can fix.
 */
class AIException extends RuntimeException
{
    public static function for(string $provider, string $reason): self
    {
        return new self("AI provider [{$provider}] failed: {$reason}");
    }
}
