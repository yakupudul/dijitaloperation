<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recipient (operator user) on an internal notification schedule.
 */
class InternalNotificationScheduleRecipient extends Model
{
    protected $fillable = [
        'schedule_id',
        'user_id',
    ];

    /**
     * @return BelongsTo<InternalNotificationSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InternalNotificationSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
