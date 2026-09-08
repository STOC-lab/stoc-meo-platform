<?php

namespace App\Exceptions;

use App\Enums\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when an organization tries to consume more of a metered feature than
 * its plan allows.
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct("Quota exceeded for [{$feature}]: {$used}/{$limit} used.");
    }

    public static function for(Feature|string $feature, int $limit, int $used): self
    {
        return new self(
            $feature instanceof Feature ? $feature->value : $feature,
            $limit,
            $used,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'ご利用中のプランの上限に達しました。プランのアップグレードをご検討ください。',
            'feature' => $this->feature,
            'limit' => $this->limit,
            'used' => $this->used,
        ], 402);
    }
}
