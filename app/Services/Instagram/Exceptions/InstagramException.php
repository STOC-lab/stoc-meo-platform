<?php

namespace App\Services\Instagram\Exceptions;

use RuntimeException;

/**
 * A call to Instagram did not produce an answer. Retrying may help.
 */
class InstagramException extends RuntimeException
{
    public static function for(string $reason): self
    {
        return new self("Instagram failed: {$reason}");
    }
}
