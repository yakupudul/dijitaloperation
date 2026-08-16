<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recipient (operator user) on a business outcome recheck schedule.
 */
class BusinessOutcomeRecheckScheduleRecipient extends Model
{
    protected $fillable = [
        'schedule_id',
        'user_id',
    ];

    /**
     * @return BelongsTo<BusinessOutcomeRecheckSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessOutcomeRecheckSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
