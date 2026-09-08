<?php

namespace App\Services;

use App\Enums\Feature;
use App\Enums\FeatureType;
use App\Models\Organization;
use App\Models\Plan;
use App\Support\Tenancy;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Answers what an organization's plan entitles it to.
 *
 * Entitlements are read from plan_features and cached per plan, since they
 * change only when a plan is edited. This class is deliberately unaware of
 * subscription state — whether a past-due organization should keep its
 * features is a policy decision made by middleware, not here.
 */
class FeatureResolver
{
    /**
     * How long a plan's resolved features stay cached.
     */
    public const CACHE_TTL = 3600;

    public function __construct(
        protected Cache $cache,
        protected Tenancy $tenancy,
    ) {}

    /**
     * Every feature granted by the organization's plan, keyed by feature key.
     *
     * @return Collection<string, int|bool|string|null>
     */
    public function features(?Organization $organization = null): Collection
    {
        $organization = $this->resolveOrganization($organization);
        $planId = $organization?->plan_id;

        if ($planId === null) {
            return collect();
        }

        return collect($this->cache->remember(
            $this->cacheKey($planId),
            self::CACHE_TTL,
            fn () => $this->load($planId),
        ));
    }

    /**
     * Whether the plan mentions the feature at all.
     */
    public function has(Feature|string $feature, ?Organization $organization = null): bool
    {
        return $this->features($organization)->has($this->key($feature));
    }

    /**
     * The raw typed value of a feature: an int or null (unlimited) for limits,
     * a bool for flags, a string for text. Null is also returned when the plan
     * does not grant the feature, so use has() to tell the two apart.
     */
    public function value(Feature|string $feature, ?Organization $organization = null): int|bool|string|null
    {
        return $this->features($organization)->get($this->key($feature));
    }

    /**
     * Whether the organization may use the feature at all. Flags are read
     * directly; a limit is usable when it is unlimited or greater than zero.
     */
    public function allows(Feature|string $feature, ?Organization $organization = null): bool
    {
        $features = $this->features($organization);
        $key = $this->key($feature);

        if (! $features->has($key)) {
            return false;
        }

        $value = $features->get($key);

        return match (true) {
            is_bool($value) => $value,
            $value === null => true,
            is_int($value) => $value > 0,
            default => filled($value),
        };
    }

    /**
     * The allowance for a limit feature: null means unlimited, and a feature
     * the plan does not grant is zero.
     */
    public function limit(Feature|string $feature, ?Organization $organization = null): ?int
    {
        $features = $this->features($organization);
        $key = $this->key($feature);

        if (! $features->has($key)) {
            return 0;
        }

        $value = $features->get($key);

        return $value === null ? null : (int) $value;
    }

    public function isUnlimited(Feature|string $feature, ?Organization $organization = null): bool
    {
        return $this->has($feature, $organization)
            && $this->limit($feature, $organization) === null;
    }

    /**
     * Drop cached entitlements. Call after editing a plan's features; with no
     * argument every plan's cache is dropped.
     */
    public function flush(Plan|Organization|int|null $plan = null): void
    {
        $planId = match (true) {
            $plan instanceof Plan => $plan->getKey(),
            $plan instanceof Organization => $plan->plan_id,
            default => $plan,
        };

        if ($planId !== null) {
            $this->cache->forget($this->cacheKey($planId));

            return;
        }

        Plan::query()->pluck('id')->each(
            fn ($id) => $this->cache->forget($this->cacheKey($id)),
        );
    }

    /**
     * @return array<string, int|bool|string|null>
     */
    protected function load(int $planId): array
    {
        return Plan::query()
            ->with('features')
            ->find($planId)
            ?->features
            ->mapWithKeys(fn ($feature) => [$feature->key => $feature->typedValue()])
            ->all() ?? [];
    }

    protected function cacheKey(int $planId): string
    {
        return "plan-features:{$planId}";
    }

    protected function key(Feature|string $feature): string
    {
        return $feature instanceof Feature ? $feature->value : $feature;
    }

    /**
     * Fall back to the organization the current request is acting on.
     */
    protected function resolveOrganization(?Organization $organization): ?Organization
    {
        return $organization ?? $this->tenancy->organization();
    }

    /**
     * Guard used by tests and seeders: the type a feature key should be stored
     * as, defaulting to a limit for keys the Feature enum does not know.
     */
    public static function typeFor(Feature|string $feature): FeatureType
    {
        $feature = $feature instanceof Feature ? $feature : Feature::tryFrom($feature);

        return $feature?->type() ?? FeatureType::Limit;
    }
}
