<?php

namespace App\Models;

use App\Enums\BusinessOutcomeDefinitionStatus;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'brand_id',
    'kind',
    'unit',
    'code',
    'display_label',
    'semantic_definition',
    'reporting_timezone',
    'currency_code',
    'status',
    'definition_version',
    'brand_goal_id',
    'created_by',
])]
class BusinessOutcomeDefinition extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BusinessOutcomeKind::class,
            'unit' => BusinessOutcomeUnit::class,
            'status' => BusinessOutcomeDefinitionStatus::class,
            'definition_version' => 'integer',
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
     * @return BelongsTo<BrandGoal, $this>
     */
    public function brandGoal(): BelongsTo
    {
        return $this->belongsTo(BrandGoal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<BusinessOutcomeObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(BusinessOutcomeObservation::class, 'definition_id');
    }

    public function isActive(): bool
    {
        return $this->status === BusinessOutcomeDefinitionStatus::Active;
    }
}
