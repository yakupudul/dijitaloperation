<?php

namespace App\Services\RecurringAutomation\Adapters;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\CollectionScheduleStatus;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\CollectionSchedule;
use App\Models\DigitalAsset;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\RecurringAutomation\Concerns\DiscoversDueOccurrences;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

/**
 * Recurring incremental collections via existing collection orchestrator (Prompt 61).
 * Never calls provider collectors directly. Never runs initial backfill.
 */
final class CollectionScheduleAdapter implements RecurringScheduleAdapter
{
    use DiscoversDueOccurrences;

    public function __construct(
        private readonly StartIncrementalCollectionService $incremental,
        private readonly RecurringOccurrenceCalculator $calculator,
    ) {}

    public function kind(): RecurringScheduleKind
    {
        return RecurringScheduleKind::Collection;
    }

    public function discoverDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? CarbonImmutable::now('UTC');
        $due = [];

        foreach (CollectionSchedule::query()->where('status', CollectionScheduleStatus::Active)->cursor() as $schedule) {
            $spec = $this->specFromSchedule($schedule);
            foreach ($this->dueFromSpec(
                (int) $schedule->id,
                $spec,
                $this->kind(),
                $nowUtc,
                $this->calculator,
                maxCatchUp: 2,
            ) as $item) {
                $due[] = $item;
            }
        }

        return $due;
    }

    public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult
    {
        $schedule = CollectionSchedule::query()->find($occurrence->domain_schedule_id);
        if ($schedule === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DOMAIN_SCHEDULE_NOT_FOUND',
                failureMessage: 'CollectionSchedule missing',
            );
        }

        $asset = DigitalAsset::query()->find($schedule->digital_asset_id);
        if ($asset === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DIGITAL_ASSET_NOT_FOUND',
                failureMessage: 'DigitalAsset missing',
            );
        }

        $actor = $schedule->created_by !== null
            ? User::query()->find($schedule->created_by)
            : null;

        $result = $this->incremental->startForDigitalAsset(
            $asset,
            $actor,
            [],
            null,
            [
                'recurring_occurrence_id' => (int) $occurrence->id,
                'collection_schedule_id' => (int) $schedule->id,
                'idempotency_suffix' => 'recurring:'.$occurrence->occurrence_key,
            ],
        );

        if ($result->outcome === 'active_equivalent' || $result->outcome === 'data_current') {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Completed,
                RecurringDomainRunType::CollectionRun,
                $result->collectionRun?->id !== null ? (int) $result->collectionRun->id : null,
                failureCode: null,
                failureMessage: $result->message,
            );
        }

        if ($result->outcome === 'started' && $result->collectionRun !== null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Completed,
                RecurringDomainRunType::CollectionRun,
                (int) $result->collectionRun->id,
            );
        }

        return new RecurringScheduleAdapterResult(
            RecurringOccurrenceStatus::Failed,
            failureCode: 'COLLECTION_START_FAILED',
            failureMessage: $result->message ?? 'Incremental collection did not start',
        );
    }

    public function isScheduleActive(int $domainScheduleId): bool
    {
        $schedule = CollectionSchedule::query()->find($domainScheduleId);

        return $schedule !== null && $schedule->status === CollectionScheduleStatus::Active;
    }

    public function allowedFrequencies(): array
    {
        return [RecurringFrequency::Hourly, RecurringFrequency::Daily];
    }

    public function defaultMisfirePolicy(): RecurringMisfirePolicy
    {
        return RecurringMisfirePolicy::CatchUpBounded;
    }

    public function supportsManualRun(): bool
    {
        return true;
    }

    private function specFromSchedule(CollectionSchedule $schedule): RecurringScheduleSpec
    {
        $time = $schedule->local_time !== null ? substr((string) $schedule->local_time, 0, 5) : '00:00';

        return new RecurringScheduleSpec(
            timezone: (string) $schedule->timezone,
            frequency: $schedule->frequency instanceof RecurringFrequency
                ? $schedule->frequency
                : RecurringFrequency::from((string) $schedule->frequency),
            interval: max(1, (int) $schedule->interval),
            localTime: $time,
            weekdays: is_array($schedule->weekdays) ? array_map('intval', $schedule->weekdays) : null,
            dayOfMonth: $schedule->day_of_month !== null ? (int) $schedule->day_of_month : null,
            monthEndPolicy: 'day_of_month',
            misfirePolicy: $schedule->misfire_policy instanceof RecurringMisfirePolicy
                ? $schedule->misfire_policy
                : RecurringMisfirePolicy::CatchUpBounded,
        );
    }
}
