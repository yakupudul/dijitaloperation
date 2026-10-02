<?php

namespace App\Models;

use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared recurring automation occurrence ledger (Prompt 61).
 */
class RecurringOccurrence extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'schedule_kind',
        'domain_schedule_id',
        'scheduled_for',
        'timezone_snapshot',
        'recurrence_spec_fingerprint',
        'status',
        'claimed_at',
        'queued_at',
        'started_at',
        'finished_at',
        'cancel_requested_at',
        'cancelled_at',
        'attempt_count',
        'domain_run_type',
        'domain_run_id',
        'failure_code',
        'failure_message',
        'is_manual',
        'created_at',
        'occurrence_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedule_kind' => RecurringScheduleKind::class,
            'scheduled_for' => 'datetime',
            'status' => RecurringOccurrenceStatus::class,
            'claimed_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attempt_count' => 'integer',
            'domain_run_type' => RecurringDomainRunType::class,
            'domain_run_id' => 'integer',
            'is_manual' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            RecurringOccurrenceStatus::Completed,
            RecurringOccurrenceStatus::Failed,
            RecurringOccurrenceStatus::Skipped,
            RecurringOccurrenceStatus::Cancelled,
        ], true);
    }
}
