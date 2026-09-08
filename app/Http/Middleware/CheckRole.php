<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationRole;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on the role the authenticated user holds in the active
 * organization. Roles are ranked, so the least privileged role listed sets the
 * bar: `role:editor` also admits admins and owners, while `role:owner` admits
 * owners only.
 */
class CheckRole
{
    public function __construct(protected Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if (! $this->tenancy->check()) {
            abort(403, '対象の組織が特定されていません。');
        }

        $required = $this->lowestRequiredRole($roles);
        $actual = $user->roleIn($this->tenancy->id());

        if ($actual === null || ! $actual->atLeast($required)) {
            abort(403, 'この操作を行う権限がありません。');
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function lowestRequiredRole(array $roles): OrganizationRole
    {
        if ($roles === []) {
            throw new InvalidArgumentException('CheckRole middleware requires at least one role.');
        }

        $resolved = array_map(
            fn (string $role) => OrganizationRole::tryFrom($role)
                ?? throw new InvalidArgumentException("Unknown organization role [{$role}]."),
            $roles,
        );

        usort($resolved, fn (OrganizationRole $a, OrganizationRole $b) => $a->level() <=> $b->level());

        return $resolved[0];
    }
}
