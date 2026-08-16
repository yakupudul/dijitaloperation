<?php

namespace App\Services\RecurringAutomation\Adapters;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\CollectionScheduleStatus;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\DigitalAsset;
use App\Models\IntelligenceSchedule;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\IntelligenceScheduling\ScheduleIntelligenceFromEvidenceService;
use App\Services\RecurringAutomation\Concerns\DiscoversDueOccurrences;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

/**
 * Prompt 63 freshness/validity recheck via Prompt61 shared runtime.
 * Does not call providers. Does not full-scan every Agent.
 */
final class IntelligenceValidityRecheckScheduleAdapter implements RecurringScheduleAdapter
{
    use DiscoversDueOccurrences;

    public function __construct(
        private readonly ScheduleIntelligenceFromEvidenceService $scheduler,
        private readonly RecurringOccurrenceCalculator $calculator,
    ) {}

    public function kind(): RecurringScheduleKind
    {
        return RecurringScheduleKind::IntelligenceValidityRecheck;
    }

    public function discoverDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? CarbonImmutable::now('UTC');
        $due = [];

        foreach (IntelligenceSchedule::query()->where('status', CollectionScheduleStatus::Active)->cursor() as $schedule) {
            $spec = $this->specFromSchedule($schedule);
            foreach ($this->dueFromSpec(
                (int) $schedule->id,
                $spec,
                $this->kind(),
                $nowUtc,
                $this->calculator,
                maxCatchUp: 1,
            ) as $item) {
                $due[] = $item;
            }
        }

        return $due;
    }

    public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult
    {
        $schedule = IntelligenceSchedule::query()->find($occurrence->domain_schedule_id);
        if ($schedule === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DOMAIN_SCHEDULE_NOT_FOUND',
                failureMessage: 'IntelligenceSchedule missing',
            );
        }

        if ($schedule->digital_asset_id === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Skipped,
                failureCode: 'NO_ASSET_SCOPE',
                failureMessage: 'Validity recheck requires an explicit DigitalAsset scope',
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

        $plan = $this->scheduler->handleValidityRecheck($asset, $actor, sync: true);

        return new RecurringScheduleAdapterResult(
            RecurringOccurrenceStatus::Completed,
            RecurringDomainRunType::IntelligenceExecutionPlan,
            $plan?->id,
            failureCode: null,
            failureMessage: $plan === null ? 'NO_PLAN' : null,
        );
    }

    public function isScheduleActive(int $domainScheduleId): bool
    {
        $schedule = IntelligenceSchedule::query()->find($domainScheduleId);

        return $schedule !== null && $schedule->status === CollectionScheduleStatus::Active;
    }

    public function allowedFrequencies(): array
    {
        return [RecurringFrequency::Hourly, RecurringFrequency::Daily];
    }

    public function defaultMisfirePolicy(): RecurringMisfirePolicy
    {
        return RecurringMisfirePolicy::RunLatestMissed;
    }

    public function supportsManualRun(): bool
    {
        return true;
    }

    private function specFromSchedule(IntelligenceSchedule $schedule): RecurringScheduleSpec
    {
        $time = $schedule->local_time !== null ? substr((string) $schedule->local_time, 0, 5) : '00:00';

        return new RecurringScheduleSpec(
            timezone: (string) $schedule->timezone,
            frequency: $schedule->frequency instanceof RecurringFrequency
                ? $schedule->frequency
                : RecurringFrequency::from((string) $schedule->frequency),
            interval: max(1, (int) $schedule->interval),
            localTime: $time,
            weekdays: null,
            dayOfMonth: null,
            monthEndPolicy: 'day_of_month',
            misfirePolicy: $schedule->misfire_policy instanceof RecurringMisfirePolicy
                ? $schedule->misfire_policy
                : RecurringMisfirePolicy::RunLatestMissed,
        );
    }
}
