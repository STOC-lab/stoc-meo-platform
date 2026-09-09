<?php

namespace App\Http\Middleware;

use App\Exceptions\FeatureNotAvailableException;
use App\Services\FeatureResolver;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on what the active organization's plan includes, reading the
 * entitlement through the FeatureResolver.
 *
 * Every feature listed must be granted — `feature:instagram.enabled,
 * instagram.auto_publish.enabled` admits only a plan carrying both. A limit
 * counts as granted while it is unlimited or greater than zero; how much of it
 * is left this period is the quota middleware's job.
 *
 * The tenant middleware has to run first, since there is nothing to check an
 * entitlement against without an organization.
 */
class EnsureFeatureIsEnabled
{
    public function __construct(
        protected FeatureResolver $features,
        protected Tenancy $tenancy,
    ) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        if ($features === []) {
            throw new InvalidArgumentException('EnsureFeatureIsEnabled middleware requires at least one feature.');
        }

        if (! $this->tenancy->check()) {
            abort(403, '対象の組織が特定されていません。');
        }

        foreach ($features as $feature) {
            if (! $this->features->allows($feature)) {
                throw new FeatureNotAvailableException($feature);
            }
        }

        return $next($request);
    }
}
