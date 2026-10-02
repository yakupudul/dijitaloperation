<?php

namespace App\Contracts\RecurringAutomation;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringScheduleKind;
use App\Models\RecurringOccurrence;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

interface RecurringScheduleAdapter
{
    public function kind(): RecurringScheduleKind;

    /**
     * @return list<array{domain_schedule_id: int, spec: RecurringScheduleSpec, scheduled_for_utc: CarbonImmutable}>
     */
    public function discoverDue(?CarbonImmutable $nowUtc = null): array;

    public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult;

    public function isScheduleActive(int $domainScheduleId): bool;

    /**
     * @return list<RecurringFrequency>
     */
    public function allowedFrequencies(): array;

    public function defaultMisfirePolicy(): RecurringMisfirePolicy;

    public function supportsManualRun(): bool;
}
