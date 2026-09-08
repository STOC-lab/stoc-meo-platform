<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-organization counter for one metered feature within one billing
 * period. Rows are created lazily by the UsageTracker.
 */
#[Fillable(['organization_id', 'key', 'period_start', 'used'])]
class UsageRecord extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'used' => 'integer',
        ];
    }

    public function feature(): ?Feature
    {
        return Feature::tryFrom($this->key);
    }
}
