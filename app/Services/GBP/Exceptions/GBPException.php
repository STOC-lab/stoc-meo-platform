<?php

namespace App\Services\GBP\Exceptions;

use RuntimeException;

/**
 * A call to a Business Profile API did not produce an answer. The caller is
 * expected to retry or to give up; it is not something the person on the other
 * end can fix.
 */
class GBPException extends RuntimeException
{
    public static function for(string $api, string $reason): self
    {
        return new self("Google Business Profile [{$api}] failed: {$reason}");
    }
}
