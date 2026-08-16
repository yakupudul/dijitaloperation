<?php

namespace App\Models;

use App\Enums\BusinessOutcomeRecheckRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business outcome recheck execution for a schedule period (Prompt 61).
 */
class BusinessOutcomeRecheckRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'schedule_id',
        'recurring_occurrence_id',
        'period_start',
        'period_end',
        'status',
        'results_payload',
        'notified',
        'created_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => BusinessOutcomeRecheckRunStatus::class,
            'results_payload' => 'array',
            'notified' => 'boolean',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessOutcomeRecheckSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessOutcomeRecheckSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<RecurringOccurrence, $this>
     */
    public function recurringOccurrence(): BelongsTo
    {
        return $this->belongsTo(RecurringOccurrence::class, 'recurring_occurrence_id');
    }
}
