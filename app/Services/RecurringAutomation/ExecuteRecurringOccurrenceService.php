<?php

namespace App\Services\RecurringAutomation;

use App\Enums\RecurringOccurrenceStatus;
use App\Models\RecurringOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Claims and executes one shared recurring occurrence via its domain adapter.
 */
final class ExecuteRecurringOccurrenceService
{
    private const STALE_RUNNING_HOURS = 2;

    public function __construct(
        private readonly RecurringAutomationRegistry $registry,
    ) {}

    public function execute(int $occurrenceId): RecurringOccurrence
    {
        $occurrence = RecurringOccurrence::query()->find($occurrenceId);
        if ($occurrence === null) {
            throw ValidationException::withMessages(['occurrence' => 'OCCURRENCE_NOT_FOUND']);
        }

        if ($occurrence->isTerminal()) {
            return $occurrence;
        }

        $claimed = $this->claim($occurrence);
        if ($claimed === null) {
            return $occurrence->fresh() ?? $occurrence;
        }

        $adapter = $this->registry->adapter($claimed->schedule_kind);

        if (! $adapter->isScheduleActive((int) $claimed->domain_schedule_id) && ! $claimed->is_manual) {
            $claimed->status = RecurringOccurrenceStatus::Skipped;
            $claimed->failure_code = 'SCHEDULE_INACTIVE';
            $claimed->failure_message = 'Schedule is not active';
            $claimed->finished_at = CarbonImmutable::now('UTC');
            $claimed->save();

            return $claimed;
        }

        try {
            $result = $adapter->execute($claimed);
            $claimed->refresh();

            $status = $result->status;
            if (! in_array($status, [
                RecurringOccurrenceStatus::Completed,
                RecurringOccurrenceStatus::Failed,
                RecurringOccurrenceStatus::Skipped,
            ], true)) {
                $status = RecurringOccurrenceStatus::Failed;
                $claimed->failure_code = $result->failureCode ?? 'INVALID_ADAPTER_STATUS';
                $claimed->failure_message = mb_substr(
                    $result->failureMessage ?? 'Adapter returned non-terminal status: '.$result->status->value,
                    0,
                    500,
                );
            } else {
                $claimed->failure_code = $result->failureCode;
                $claimed->failure_message = $result->failureMessage !== null
                    ? mb_substr($result->failureMessage, 0, 500)
                    : null;
            }

            $claimed->status = $status;
            $claimed->domain_run_type = $result->domainRunType;
            $claimed->domain_run_id = $result->domainRunId;
            $claimed->finished_at = CarbonImmutable::now('UTC');
            $claimed->save();

            return $claimed;
        } catch (\Throwable $e) {
            $claimed->status = RecurringOccurrenceStatus::Failed;
            $claimed->failure_code = 'ADAPTER_EXCEPTION';
            $claimed->failure_message = mb_substr($e->getMessage(), 0, 500);
            $claimed->finished_at = CarbonImmutable::now('UTC');
            $claimed->save();

            return $claimed;
        }
    }

    private function claim(RecurringOccurrence $occurrence): ?RecurringOccurrence
    {
        return DB::transaction(function () use ($occurrence): ?RecurringOccurrence {
            $locked = RecurringOccurrence::query()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            if ($locked->isTerminal() || $locked->status === RecurringOccurrenceStatus::CancelRequested) {
                if ($locked->status === RecurringOccurrenceStatus::CancelRequested) {
                    $locked->status = RecurringOccurrenceStatus::Cancelled;
                    $locked->cancelled_at = CarbonImmutable::now('UTC');
                    $locked->finished_at = CarbonImmutable::now('UTC');
                    $locked->save();
                }

                return null;
            }

            $staleCutoff = CarbonImmutable::now('UTC')->subHours(self::STALE_RUNNING_HOURS);
            $isClaimable = in_array($locked->status, [
                RecurringOccurrenceStatus::Pending,
                RecurringOccurrenceStatus::Queued,
            ], true);

            $isStaleRunning = $locked->status === RecurringOccurrenceStatus::Running
                && $locked->started_at !== null
                && $locked->started_at->lessThan($staleCutoff)
                && (int) $locked->attempt_count < 2;

            if (! $isClaimable && ! $isStaleRunning) {
                return null;
            }

            $locked->status = RecurringOccurrenceStatus::Running;
            $locked->claimed_at = CarbonImmutable::now('UTC');
            $locked->started_at = CarbonImmutable::now('UTC');
            $locked->attempt_count = (int) $locked->attempt_count + 1;
            $locked->save();

            return $locked;
        });
    }
}
