<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recipient on a report delivery schedule (Prompt 60).
 */
class ReportDeliveryScheduleRecipient extends Model
{
    protected $fillable = [
        'schedule_id',
        'email',
        'display_name',
        'locale_override',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ReportDeliverySchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportDeliverySchedule::class, 'schedule_id');
    }
}
