<?php

namespace App\Support\RecurringAutomation;

use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringOccurrenceStatus;

/**
 * Result returned by a domain schedule adapter after executing one occurrence.
 */
readonly class RecurringScheduleAdapterResult
{
    public function __construct(
        public RecurringOccurrenceStatus $status,
        public ?RecurringDomainRunType $domainRunType = null,
        public ?int $domainRunId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}
