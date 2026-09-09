<?php

namespace App\Exceptions;

use App\Enums\Feature;
use App\Services\PlanUpgradeAdvisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base for the refusals that come from an organization's plan rather than from
 * its permissions: the feature is not part of the plan, or its allowance for
 * the period is spent.
 *
 * Both answer 403 with the same envelope, so the SPA can render one upgrade
 * prompt wherever a refusal comes from.
 */
abstract class EntitlementException extends RuntimeException
{
    public function __construct(public readonly string $feature, string $message)
    {
        parent::__construct($message);
    }

    /**
     * What the person on the other end is told.
     */
    abstract protected function userMessage(): string;

    /**
     * Extra fields describing the refusal, merged into the response.
     *
     * @return array<string, mixed>
     */
    protected function details(): array
    {
        return [];
    }

    /**
     * Running out of plan is an expected answer, not a fault, so it stays out
     * of the logs.
     */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->userMessage(),
            'feature' => $this->feature,
            ...$this->details(),
            'upgrade' => app(PlanUpgradeAdvisor::class)->callToAction($this->feature),
        ], 403);
    }

    protected static function key(Feature|string $feature): string
    {
        return $feature instanceof Feature ? $feature->value : $feature;
    }
}
