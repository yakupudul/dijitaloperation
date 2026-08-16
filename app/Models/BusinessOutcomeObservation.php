<?php

namespace App\Models;

use App\Enums\BusinessOutcomeObservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'brand_id',
    'definition_id',
    'period_start',
    'period_end',
    'status',
    'current_revision_id',
    'semantic_key',
])]
class BusinessOutcomeObservation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => BusinessOutcomeObservationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<BusinessOutcomeDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(BusinessOutcomeDefinition::class, 'definition_id');
    }

    /**
     * @return BelongsTo<BusinessOutcomeObservationRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(BusinessOutcomeObservationRevision::class, 'current_revision_id');
    }

    /**
     * @return HasMany<BusinessOutcomeObservationRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(BusinessOutcomeObservationRevision::class, 'observation_id');
    }

    public function isActive(): bool
    {
        return $this->status === BusinessOutcomeObservationStatus::Active;
    }
}
