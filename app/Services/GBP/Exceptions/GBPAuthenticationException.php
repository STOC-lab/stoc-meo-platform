<?php

namespace App\Services\GBP\Exceptions;

/**
 * Google refused the connection's credentials — a 401, or a refresh that came
 * back rejected.
 *
 * This is the one failure retrying cannot fix: the connection is marked
 * unusable and an alert is raised so someone reconnects the account. Callers
 * should let it through rather than swallowing it into a generic failure.
 */
class GBPAuthenticationException extends GBPException
{
    public static function for(string $api, string $reason): self
    {
        return new self("Google Business Profile [{$api}] rejected the connection: {$reason}");
    }
}
