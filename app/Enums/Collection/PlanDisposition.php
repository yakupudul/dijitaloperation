<?php

namespace App\Enums\Collection;

/**
 * Plan-time disposition for a requirement/dataset before execution.
 * Distinct from CollectionRunStatus lifecycle.
 */
enum PlanDisposition: string
{
    case Eligible = 'eligible';
    case NotEligible = 'not_eligible';
    case AlreadySatisfied = 'already_satisfied';
    case ActionRequired = 'action_required';
    case CollectorUnavailable = 'collector_unavailable';
    case Unsupported = 'unsupported';
    case Deferred = 'deferred';
    case SkippedProviderFilter = 'skipped_provider_filter';
    case SkippedSourceContract = 'skipped_source_contract';
    case IntegrityBlocked = 'integrity_blocked';
    case ProviderLimited = 'provider_limited';

    /**
     * Whether this disposition creates an executable (queued) DatasetRun.
     */
    public function createsExecutableDatasetRun(): bool
    {
        return $this === self::Eligible;
    }
}
