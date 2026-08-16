<?php

namespace App\Models;

use App\Enums\CollectionScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Collection domain schedule bound to the shared recurring automation engine (Prompt 61).
 */
class CollectionSchedule extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'frequency',
        'interval',
        'timezone',
        'local_time',
        'day_of_month',
        'weekdays',
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
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'weekdays' => 'array',
            'misfire_policy' => RecurringMisfirePolicy::class,
            'status' => CollectionScheduleStatus::class,
            'next_run_at' => 'datetime',
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
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
