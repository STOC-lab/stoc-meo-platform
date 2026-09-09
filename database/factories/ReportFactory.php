<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Organization;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $period = now()->subMonth()->startOfMonth();

        return [
            'organization_id' => Organization::factory(),
            'period_start' => $period->toDateString(),
            'status' => ReportStatus::Pending,
            'path' => null,
            'size' => null,
            'generated_at' => null,
            'failure_reason' => null,
        ];
    }

    public function forPeriod(string $yearMonth): static
    {
        return $this->state(fn () => ['period_start' => $yearMonth.'-01']);
    }

    /**
     * A finished report. The path is filled in afterwards rather than in the
     * state, since the organization is still a factory until the row exists.
     */
    public function completed(): static
    {
        return $this
            ->state(fn () => [
                'status' => ReportStatus::Completed,
                'size' => 24_576,
                'generated_at' => now(),
            ])
            ->afterCreating(function (Report $report) {
                $report->forceFill([
                    'path' => Report::pathFor($report->organization_id, $report->period_start),
                ])->save();
            });
    }

    public function failed(string $reason = 'レポートの作成に失敗しました。'): static
    {
        return $this->state(fn () => [
            'status' => ReportStatus::Failed,
            'generated_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
