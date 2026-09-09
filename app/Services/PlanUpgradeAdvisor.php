<?php

namespace App\Services;

use App\Enums\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

/**
 * Works out where to send an organization that has run into the edge of its
 * plan, so a refusal can carry a concrete next step rather than a dead end.
 *
 * Recommendations are drawn from the live catalogue: the cheapest active plan
 * of the same product that grants more of the feature than the organization
 * holds today.
 */
class PlanUpgradeAdvisor
{
    public function __construct(
        protected FeatureResolver $features,
        protected Tenancy $tenancy,
    ) {}

    /**
     * The plan to point the organization at, or null when nothing on sale
     * grants more of the feature than it already has.
     */
    public function recommend(Feature|string $feature, ?Organization $organization = null): ?Plan
    {
        $organization = $this->resolveOrganization($organization);
        $key = $this->key($feature);
        $current = $organization?->plan_id === null
            ? null
            : $this->features->value($key, $organization);

        return $this->candidates($organization)
            ->first(fn (Plan $plan) => $this->grantsMoreThan($plan, $key, $current));
    }

    /**
     * The upgrade call to action returned alongside a refusal, shaped for the
     * SPA to render without knowing anything about the catalogue.
     *
     * @return array<string, mixed>
     */
    public function callToAction(Feature|string $feature, ?Organization $organization = null): array
    {
        $organization = $this->resolveOrganization($organization);
        $recommended = $this->recommend($feature, $organization);

        return [
            'required' => true,
            'headline' => $recommended === null
                ? 'この機能をご利用いただけるプランがありません。サポートまでお問い合わせください。'
                : "「{$recommended->name}」にアップグレードすると引き続きご利用いただけます。",
            'cta_label' => $recommended === null ? 'サポートに問い合わせる' : 'プランをアップグレード',
            'cta_url' => $this->plansUrl(),
            'current_plan' => $this->present($organization?->plan),
            'recommended_plan' => $this->present($recommended),
        ];
    }

    /**
     * Active plans of the organization's product, cheapest first. An
     * organization with no plan yet sees the whole catalogue.
     *
     * @return Collection<int, Plan>
     */
    protected function candidates(?Organization $organization): Collection
    {
        $product = $organization?->plan?->product;

        return Plan::query()
            ->active()
            ->when($product !== null, fn ($query) => $query->product($product))
            ->with('features')
            ->orderBy('price')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Whether the plan grants more of the feature than the organization holds.
     * This is only ever asked after a refusal, so the current entitlement is a
     * flag that is off, a finite limit, or nothing at all.
     */
    protected function grantsMoreThan(Plan $plan, string $key, int|bool|string|null $current): bool
    {
        $granted = $plan->feature($key);

        if (! $granted instanceof PlanFeature) {
            return false;
        }

        $value = $granted->typedValue();

        return match (true) {
            is_bool($value) => $value === true,
            $value === null => true,
            is_int($value) => $value > (is_int($current) ? $current : 0),
            default => filled($value),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function present(?Plan $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'price' => $plan->price,
            'currency' => $plan->currency,
        ];
    }

    protected function plansUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/billing/plans';
    }

    protected function resolveOrganization(?Organization $organization): ?Organization
    {
        return $organization ?? $this->tenancy->organization();
    }

    protected function key(Feature|string $feature): string
    {
        return $feature instanceof Feature ? $feature->value : $feature;
    }
}
