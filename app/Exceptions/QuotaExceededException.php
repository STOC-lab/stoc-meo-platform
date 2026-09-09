<?php

namespace App\Exceptions;

use App\Enums\Feature;

/**
 * Thrown when an organization tries to consume more of a metered feature than
 * its plan allows for the period.
 */
class QuotaExceededException extends EntitlementException
{
    public function __construct(
        string $feature,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct($feature, "Quota exceeded for [{$feature}]: {$used}/{$limit} used.");
    }

    public static function for(Feature|string $feature, int $limit, int $used): self
    {
        return new self(static::key($feature), $limit, $used);
    }

    protected function userMessage(): string
    {
        return 'ご利用中のプランの今月の上限に達しました。';
    }

    /**
     * @return array<string, mixed>
     */
    protected function details(): array
    {
        return [
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => max(0, $this->limit - $this->used),
        ];
    }
}
