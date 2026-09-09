<?php

namespace App\Http\Middleware;

use App\Exceptions\QuotaExceededException;
use App\Services\FeatureResolver;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route once the active organization has spent its allowance of a
 * metered feature for the period — `quota:gbp.post.monthly_limit`, or
 * `quota:gbp.post.monthly_limit,5` when one request consumes five.
 *
 * This only checks; it never records. The counter is moved by the action
 * itself once the work has actually happened, through UsageTracker::consume()
 * or record(), so a request that fails downstream costs the organization
 * nothing.
 *
 * The tenant middleware has to run first, since usage is counted per
 * organization.
 */
class EnforceUsageQuota
{
    public function __construct(
        protected FeatureResolver $features,
        protected UsageTracker $usage,
        protected Tenancy $tenancy,
    ) {}

    public function handle(Request $request, Closure $next, string $feature, int|string $amount = 1): Response
    {
        if (! $this->tenancy->check()) {
            abort(403, '対象の組織が特定されていません。');
        }

        $amount = max(1, (int) $amount);
        $limit = $this->features->limit($feature);

        // A null limit is an unlimited allowance; a feature the plan omits
        // resolves to zero and is refused here rather than at consumption.
        if ($limit !== null) {
            $used = $this->usage->used($feature);

            if ($used + $amount > $limit) {
                throw QuotaExceededException::for($feature, $limit, $used);
            }
        }

        return $next($request);
    }
}
