<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One month's PDF report for an organization.
 *
 * The row is the record of the run; the PDF itself lives on the reports disk
 * at the path below, which is never served directly.
 */
#[Fillable([
    'organization_id',
    'period_start',
    'status',
    'path',
    'size',
    'generated_at',
    'failure_reason',
])]
class Report extends Model
{
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'status' => ReportStatus::class,
            'size' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Where the PDF for a period sits on the reports disk.
     */
    public static function pathFor(int $organizationId, CarbonInterface $periodStart): string
    {
        return $organizationId.'/'.$periodStart->format('Y-m').'.pdf';
    }

    /**
     * The month reported on, as a person reads it.
     */
    public function periodLabel(): string
    {
        return $this->period_start->format('Y年n月');
    }

    public function markGenerated(string $path, int $size): void
    {
        $this->forceFill([
            'status' => ReportStatus::Completed,
            'path' => $path,
            'size' => $size,
            'generated_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => ReportStatus::Failed,
            'generated_at' => now(),
            // The column is narrower than an exception message can be.
            'failure_reason' => mb_substr($reason, 0, 255),
        ])->save();
    }
}
