<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'digital_asset_id',
    'conversion_action_id',
    'business_stage',
    'business_action_label',
    'nominal_value',
    'currency',
    'is_quality_signal',
    'notes',
    'created_by_user_id',
    'updated_by_user_id',
])]
class GoogleAdsConversionBusinessMapping extends Model
{
    protected function casts(): array
    {
        return [
            'nominal_value' => 'decimal:6',
            'is_quality_signal' => 'boolean',
        ];
    }

    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
