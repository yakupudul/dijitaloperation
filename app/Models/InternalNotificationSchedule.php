<?php

namespace App\Models;

use App\Enums\InternalNotificationScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal operator notification schedule (Prompt 61).
 */
class InternalNotificationSchedule extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_id',
        'timezone',
        'frequency',
        'interval',
        'local_time',
        'day_of_month',
        'weekdays',
        'title',
        'message',
        'safe_route_name',
        'misfire_policy',
        'status',
        'created_by',
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
            'status' => InternalNotificationScheduleStatus::class,
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
     * @return HasMany<InternalNotificationScheduleRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(InternalNotificationScheduleRecipient::class, 'schedule_id');
    }
}
