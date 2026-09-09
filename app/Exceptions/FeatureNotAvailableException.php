<?php

namespace App\Exceptions;

use App\Enums\Feature;

/**
 * Thrown when an organization reaches for a feature its plan does not include
 * at all, as opposed to one whose allowance is merely spent.
 */
class FeatureNotAvailableException extends EntitlementException
{
    public function __construct(Feature|string $feature)
    {
        $key = static::key($feature);

        parent::__construct($key, "Feature [{$key}] is not included in the organization's plan.");
    }

    protected function userMessage(): string
    {
        return 'ご利用中のプランではこの機能をご利用いただけません。';
    }
}
