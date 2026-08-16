<?php

namespace App\Models;

use App\Enums\BusinessOutcomeRecheckPeriodStrategy;
use App\Enums\BusinessOutcomeRecheckScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Business outcome recheck schedule (Prompt 61).
 */
class BusinessOutcomeRecheckSchedule extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_id',
        'locale',
        'timezone',
        'frequency',
        'day_of_month',
        'weekdays',
        'delivery_time',
        'period_strategy',
        'misfire_policy',
        'status',
        'attention_on_no_data',
        'attention_on_partial',
        'attention_on_unknown',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RecurringFrequency::class,
            'day_of_month' => 'integer',
            'weekdays' => 'array',
            'period_strategy' => BusinessOutcomeRecheckPeriodStrategy::class,
            'misfire_policy' => RecurringMisfirePolicy::class,
            'status' => BusinessOutcomeRecheckScheduleStatus::class,
            'attention_on_no_data' => 'boolean',
            'attention_on_partial' => 'boolean',
            'attention_on_unknown' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<BusinessOutcomeRecheckScheduleRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(BusinessOutcomeRecheckScheduleRecipient::class, 'schedule_id');
    }

    /**
     * @return HasMany<BusinessOutcomeRecheckRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(BusinessOutcomeRecheckRun::class, 'schedule_id');
    }
}
