<?php

namespace App\Services\RecurringAutomation;

use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Jobs\RecurringAutomation\ExecuteRecurringOccurrenceJob;
use App\Models\RecurringOccurrence;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Discovers due domain schedules and queues shared recurring occurrences (Prompt 61).
 */
final class RecurringAutomationDispatcher
{
    public function __construct(
        private readonly RecurringAutomationRegistry $registry,
    ) {}

    /**
     * @param  list<RecurringScheduleKind|string>|null  $onlyKinds
     * @return list<int> occurrence IDs queued/dispatched
     */
    public function dispatchDue(?CarbonImmutable $now = null, ?array $onlyKinds = null): array
    {
        $now = $now ?? CarbonImmutable::now('UTC');
        $kindFilter = $this->normalizeKinds($onlyKinds);
        $ids = [];

        foreach ($this->registry->all() as $adapter) {
            if ($kindFilter !== null && ! in_array($adapter->kind(), $kindFilter, true)) {
                continue;
            }

            foreach ($adapter->discoverDue($now) as $due) {
                $domainScheduleId = (int) $due['domain_schedule_id'];
                /** @var RecurringScheduleSpec $spec */
                $spec = $due['spec'];
                /** @var CarbonImmutable $scheduledForUtc */
                $scheduledForUtc = $due['scheduled_for_utc']->setTimezone('UTC');

                $occurrence = $this->ensureOccurrence(
                    $adapter->kind(),
                    $domainScheduleId,
                    $scheduledForUtc,
                    $spec,
                );

                if ($occurrence->isTerminal()) {
                    continue;
                }

                if ($occurrence->status !== RecurringOccurrenceStatus::Pending) {
                    continue;
                }

                if (! $adapter->isScheduleActive($domainScheduleId)) {
                    continue;
                }

                $queued = DB::transaction(function () use ($occurrence): ?RecurringOccurrence {
                    $locked = RecurringOccurrence::query()
                        ->whereKey($occurrence->id)
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null || $locked->status !== RecurringOccurrenceStatus::Pending) {
                        return null;
                    }

                    $locked->status = RecurringOccurrenceStatus::Queued;
                    $locked->queued_at = CarbonImmutable::now('UTC');
                    $locked->save();

                    return $locked;
                });

                if ($queued === null) {
                    continue;
                }

                ExecuteRecurringOccurrenceJob::dispatch((int) $queued->id);
                $ids[] = (int) $queued->id;
            }
        }

        return $ids;
    }

    private function ensureOccurrence(
        RecurringScheduleKind $kind,
        int $domainScheduleId,
        CarbonImmutable $scheduledForUtc,
        RecurringScheduleSpec $spec,
    ): RecurringOccurrence {
        $key = $this->occurrenceKey($kind, $domainScheduleId, $scheduledForUtc);

        return DB::transaction(function () use ($kind, $domainScheduleId, $scheduledForUtc, $spec, $key): RecurringOccurrence {
            $existing = RecurringOccurrence::query()
                ->where('occurrence_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $byUnique = RecurringOccurrence::query()
                ->where('schedule_kind', $kind)
                ->where('domain_schedule_id', $domainScheduleId)
                ->where('scheduled_for', $scheduledForUtc)
                ->lockForUpdate()
                ->first();

            if ($byUnique !== null) {
                return $byUnique;
            }

            return RecurringOccurrence::query()->create([
                'schedule_kind' => $kind,
                'domain_schedule_id' => $domainScheduleId,
                'scheduled_for' => $scheduledForUtc,
                'timezone_snapshot' => $spec->timezone,
                'recurrence_spec_fingerprint' => $spec->fingerprint(),
                'status' => RecurringOccurrenceStatus::Pending,
                'attempt_count' => 0,
                'is_manual' => false,
                'created_at' => CarbonImmutable::now('UTC'),
                'occurrence_key' => $key,
            ]);
        });
    }

    public function occurrenceKey(
        RecurringScheduleKind $kind,
        int $domainScheduleId,
        CarbonImmutable $scheduledForUtc,
    ): string {
        return $kind->value.':'.$domainScheduleId.':'.$scheduledForUtc->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  list<RecurringScheduleKind|string>|null  $onlyKinds
     * @return list<RecurringScheduleKind>|null
     */
    private function normalizeKinds(?array $onlyKinds): ?array
    {
        if ($onlyKinds === null || $onlyKinds === []) {
            return null;
        }

        $normalized = [];
        foreach ($onlyKinds as $kind) {
            if ($kind instanceof RecurringScheduleKind) {
                $normalized[] = $kind;

                continue;
            }

            $enum = RecurringScheduleKind::tryFrom((string) $kind);
            if ($enum === null) {
                throw new \InvalidArgumentException('Unknown recurring schedule kind: '.(string) $kind);
            }
            $normalized[] = $enum;
        }

        return $normalized;
    }
}
