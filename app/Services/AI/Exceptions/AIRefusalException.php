<?php

namespace App\Services\AI\Exceptions;

/**
 * The model declined to answer.
 *
 * Retrying the same request will get the same answer, so this ends the work
 * rather than spending the attempts on it, and the draft is marked failed with
 * something a person can act on.
 */
class AIRefusalException extends AIException
{
    public function __construct(public readonly ?string $category, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $provider, ?string $category): self
    {
        return new self(
            $category,
            "AI provider [{$provider}] declined the request".($category === null ? '.' : " ({$category})."),
        );
    }
}
