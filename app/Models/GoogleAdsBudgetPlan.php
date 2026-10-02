<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleAdsBudgetPlan extends Model
{
    protected $fillable = [
        'digital_asset_id',
        'period_start',
        'period_end',
        'currency',
        'planned_budget',
        'target_cpa',
        'target_roas',
        'notes',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'planned_budget' => 'decimal:2',
            'target_cpa' => 'decimal:2',
            'target_roas' => 'decimal:4',
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
