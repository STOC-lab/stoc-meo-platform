<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AIException;

/**
 * A source of generated text.
 *
 * The interface is deliberately one call: everything the product asks a model
 * for is a single prompt and a single answer, and nothing here needs a
 * conversation or tools. A provider that cannot answer throws AIException; one
 * that declines throws AIRefusalException, which the caller must not retry.
 */
interface AIProviderInterface
{
    /**
     * The name recorded against what this provider produces.
     */
    public function name(): string;

    /**
     * Whether the provider is configured well enough to be worth calling.
     */
    public function isAvailable(): bool;

    /**
     * Ask for one piece of text.
     *
     * @param  array<string, mixed>  $options  model, max_tokens, temperature
     *
     * @throws AIException
     */
    public function complete(string $prompt, ?string $system = null, array $options = []): AIResult;
}
