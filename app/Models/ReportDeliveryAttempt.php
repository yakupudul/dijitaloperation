<?php

namespace App\Models;

use App\Enums\ReportDeliveryAttemptResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transport attempt record for a report delivery (Prompt 60).
 */
class ReportDeliveryAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'attempt_number',
        'started_at',
        'finished_at',
        'result',
        'transport_message_id',
        'error_class',
        'error_message',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result' => ReportDeliveryAttemptResult::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ReportDelivery::class, 'delivery_id');
    }
}
