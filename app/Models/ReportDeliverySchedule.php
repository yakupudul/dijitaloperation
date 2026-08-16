<?php

namespace App\Models;

use App\Enums\ReportDeliveryScheduleCadence;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Enums\ReportPeriodStrategy;
use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recurring report delivery schedule (Prompt 60).
 */
class ReportDeliverySchedule extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_id',
        'report_type',
        'locale',
        'timezone',
        'cadence',
        'day_of_month',
        'delivery_time',
        'period_strategy',
        'share_ttl_hours',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'cadence' => ReportDeliveryScheduleCadence::class,
            'day_of_month' => 'integer',
            'period_strategy' => ReportPeriodStrategy::class,
            'share_ttl_hours' => 'integer',
            'status' => ReportDeliveryScheduleStatus::class,
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
     * @return HasMany<ReportDeliveryScheduleRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(ReportDeliveryScheduleRecipient::class, 'schedule_id');
    }

    /**
     * @return HasMany<ReportDeliveryOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ReportDeliveryOccurrence::class, 'schedule_id');
    }
}
