<?php

namespace App\Models;

use App\Enums\Feature;
use App\Enums\FeatureType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability or allowance granted by a plan. The value is kept as a string
 * so limits, flags and free-form values share a table; read it through
 * typedValue() rather than the raw attribute.
 */
#[Fillable(['plan_id', 'key', 'type', 'value'])]
class PlanFeature extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FeatureType::class,
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function feature(): ?Feature
    {
        return Feature::tryFrom($this->key);
    }

    /**
     * The value read according to the feature's type: an int or null
     * (unlimited) for limits, a bool for flags, a string otherwise.
     */
    public function typedValue(): int|bool|string|null
    {
        return match ($this->type) {
            FeatureType::Limit => $this->value === null ? null : (int) $this->value,
            FeatureType::Boolean => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            FeatureType::Text => $this->value,
        };
    }
}
