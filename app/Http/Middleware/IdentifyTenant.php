<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization the request is acting on and activates it for the
 * rest of the request, so the BelongsToTenant global scope can constrain every
 * query. The organization comes from the route parameter, the
 * X-Organization-Id header, or — when neither is given and the user belongs to
 * exactly one organization — that membership.
 */
class IdentifyTenant
{
    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $identifier = $this->identifier($request);

        $organization = $identifier === null
            ? $this->soleOrganization($request)
            : $this->findOrganization($identifier);

        if ($organization === null) {
            abort(403, '対象の組織を特定できませんでした。');
        }

        if (! $user->belongsToOrganization($organization)) {
            abort(403, 'この組織へのアクセス権がありません。');
        }

        $this->tenancy->set($organization);

        return $next($request);
    }

    protected function identifier(Request $request): int|string|null
    {
        $route = $request->route()?->parameter('organization');

        if ($route instanceof Organization) {
            return $route->getKey();
        }

        return $route ?? $request->header('X-Organization-Id');
    }

    protected function findOrganization(int|string $identifier): ?Organization
    {
        return Organization::query()
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier),
            )
            ->first();
    }

    /**
     * Fall back to the user's organization when they only have one, so simple
     * single-tenant clients do not need to send an identifier.
     */
    protected function soleOrganization(Request $request): ?Organization
    {
        $organizations = $request->user()->organizations()->take(2)->get();

        return $organizations->count() === 1 ? $organizations->first() : null;
    }
}
