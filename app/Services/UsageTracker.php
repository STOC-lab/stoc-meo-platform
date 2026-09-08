<?php

namespace App\Services;

use App\Enums\Feature;
use App\Exceptions\QuotaExceededException;
use App\Models\Organization;
use App\Models\UsageRecord;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Counts consumption of metered features per organization and billing period,
 * and enforces the allowance the plan grants.
 *
 * Periods are calendar months in the application timezone. Counters are stored
 * per period rather than reset in place, so past periods stay auditable.
 */
class UsageTracker
{
    public function __construct(
        protected FeatureResolver $features,
        protected Tenancy $tenancy,
    ) {}

    /**
     * How much of the feature the organization has consumed this period.
     */
    public function used(Feature|string $feature, ?Organization $organization = null, ?DateTimeInterface $at = null): int
    {
        $organization = $this->organization($organization);

        return (int) ($this->query($organization, $feature, $at)->value('used') ?? 0);
    }

    /**
     * How much is left this period, or null when the plan grants an unlimited
     * allowance.
     */
    public function remaining(Feature|string $feature, ?Organization $organization = null, ?DateTimeInterface $at = null): ?int
    {
        $organization = $this->organization($organization);
        $limit = $this->features->limit($feature, $organization);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->used($feature, $organization, $at));
    }

    /**
     * Whether the organization can consume the given amount right now.
     */
    public function canUse(Feature|string $feature, int $amount = 1, ?Organization $organization = null, ?DateTimeInterface $at = null): bool
    {
        $remaining = $this->remaining($feature, $organization, $at);

        return $remaining === null || $remaining >= $amount;
    }

    /**
     * Add to the counter without checking the allowance. Use when the work has
     * already happened and must be recorded regardless.
     */
    public function record(Feature|string $feature, int $amount = 1, ?Organization $organization = null, ?DateTimeInterface $at = null): UsageRecord
    {
        $organization = $this->organization($organization);
        $attributes = [
            'organization_id' => $organization->getKey(),
            'key' => $this->key($feature),
            'period_start' => $this->periodStart($at)->toDateString(),
        ];

        return DB::transaction(function () use ($attributes, $amount) {
            $record = UsageRecord::acrossTenants()->firstOrCreate($attributes, ['used' => 0]);

            $record->increment('used', $amount);

            return $record->refresh();
        });
    }

    /**
     * Consume the allowance, refusing when the plan's limit would be exceeded.
     *
     * @throws QuotaExceededException
     */
    public function consume(Feature|string $feature, int $amount = 1, ?Organization $organization = null, ?DateTimeInterface $at = null): UsageRecord
    {
        $organization = $this->organization($organization);
        $limit = $this->features->limit($feature, $organization);

        if ($limit !== null) {
            $used = $this->used($feature, $organization, $at);

            if ($used + $amount > $limit) {
                throw QuotaExceededException::for($feature, $limit, $used);
            }
        }

        return $this->record($feature, $amount, $organization, $at);
    }

    /**
     * Clear the counter for the period, e.g. after a plan change mid-cycle.
     */
    public function reset(Feature|string $feature, ?Organization $organization = null, ?DateTimeInterface $at = null): void
    {
        $this->query($this->organization($organization), $feature, $at)->delete();
    }

    /**
     * Used, limit and remaining for every metered feature this period.
     *
     * @return array<string, array{used: int, limit: int|null, remaining: int|null}>
     */
    public function summary(?Organization $organization = null, ?DateTimeInterface $at = null): array
    {
        $organization = $this->organization($organization);
        $summary = [];

        foreach (Feature::cases() as $feature) {
            if (! $feature->isMetered() || ! $this->features->has($feature, $organization)) {
                continue;
            }

            $summary[$feature->value] = [
                'used' => $this->used($feature, $organization, $at),
                'limit' => $this->features->limit($feature, $organization),
                'remaining' => $this->remaining($feature, $organization, $at),
            ];
        }

        return $summary;
    }

    /**
     * The first day of the billing period the given moment falls in.
     */
    public function periodStart(?DateTimeInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ? CarbonImmutable::instance($at) : CarbonImmutable::now())
            ->startOfMonth()
            ->startOfDay();
    }

    /**
     * @return Builder<UsageRecord>
     */
    protected function query(Organization $organization, Feature|string $feature, ?DateTimeInterface $at)
    {
        return UsageRecord::acrossTenants()
            ->where('organization_id', $organization->getKey())
            ->where('key', $this->key($feature))
            ->where('period_start', $this->periodStart($at)->toDateString());
    }

    protected function key(Feature|string $feature): string
    {
        return $feature instanceof Feature ? $feature->value : $feature;
    }

    /**
     * Fall back to the organization the current request is acting on.
     */
    protected function organization(?Organization $organization): Organization
    {
        $organization ??= $this->tenancy->organization();

        if ($organization === null) {
            throw new \RuntimeException('No organization is active; pass one explicitly to the UsageTracker.');
        }

        return $organization;
    }
}
