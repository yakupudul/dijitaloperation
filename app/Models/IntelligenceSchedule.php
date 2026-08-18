<?php

namespace App\Models;

use App\Enums\CollectionScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bounded Prompt61 schedule for Evidence validity / freshness rechecks (Prompt 63).
 */
class IntelligenceSchedule extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'frequency',
        'interval',
        'timezone',
        'local_time',
        'misfire_policy',
        'status',
        'created_by',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RecurringFrequency::class,
            'misfire_policy' => RecurringMisfirePolicy::class,
            'status' => CollectionScheduleStatus::class,
            'next_run_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }
}
