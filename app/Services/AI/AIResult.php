<?php

namespace App\Services\AI;

/**
 * What a model returned for one request: the text it wrote, which model wrote
 * it, and what the call cost in tokens.
 */
final class AIResult
{
    /**
     * @param  array<int, string>  $hashtags
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly array $hashtags = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $stopReason = null,
    ) {}

    /**
     * Whether the model ran out of room mid-sentence, which means the text is
     * cut short rather than finished.
     */
    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
