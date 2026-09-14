<?php

namespace App\Services\GBP\Exceptions;

use RuntimeException;

/**
 * A call to a Business Profile API did not produce an answer. The caller is
 * expected to retry or to give up; it is not something the person on the other
 * end can fix.
 *
 * Whether it is worth asking again is the caller's whole decision, so the
 * failure carries it. A transient failure — a quota that resets, a timeout, an
 * outage at Google's end — describes the moment rather than the request, and
 * the same call a quarter of an hour later would be answered. A permanent one
 * is Google's settled answer: an API that is not enabled on the project, a
 * malformed request, a location that is not there. Retrying that only spends
 * attempts on the same refusal, and somebody has to go and change something.
 */
class GBPException extends RuntimeException
{
    public function __construct(string $message, protected bool $transient = false)
    {
        parent::__construct($message);
    }

    public static function for(string $api, string $reason): self
    {
        return new self("Google Business Profile [{$api}] failed: {$reason}");
    }

    /**
     * A failure that describes the moment rather than the request.
     */
    public static function transient(string $api, string $reason): self
    {
        return new self("Google Business Profile [{$api}] failed: {$reason}", transient: true);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
