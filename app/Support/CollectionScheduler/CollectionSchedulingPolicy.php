<?php

namespace App\Support\CollectionScheduler;

/**
 * Versioned Provider × ResourceType × Dataset scheduling policy (code/config owned).
 * Values come from Prompt 7 Data Contract + Prompt 27 Freshness Policy — never magic guesses.
 */
final class CollectionSchedulingPolicy
{
    /**
     * @param  array<string, mixed>  $lateDataRepair
     * @param  array<string, mixed>  $requiredHistory
     * @param  list<string>|string|null  $reportingGrain
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly string $providerOrSource,
        public readonly string $resourceType,
        public readonly bool $eligible,
        public readonly ?string $ineligibilityReason,
        public readonly string $collectionMode,
        public readonly array $requiredHistory,
        public readonly array|string|null $reportingGrain,
        public readonly string $timezoneSource,
        public readonly ?int $safeCollectionLagDays,
        public readonly bool $currentPeriodCollectable,
        public readonly ?string $expectedRefreshCadence,
        public readonly bool $incrementalApplicable,
        public readonly bool $lateDataRepairEnabled,
        public readonly array $lateDataRepair,
        public readonly bool $catchUpEnabled,
        public readonly ?int $maxBoundedIncrementalSpanDays,
        public readonly ?string $rateLimitClass,
        public readonly ?string $costClass,
        public readonly string $policyIdentity,
        public readonly int $policyVersion,
        public readonly string $policyFingerprint,
        public readonly array $raw,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'provider_or_source' => $this->providerOrSource,
            'resource_type' => $this->resourceType,
            'eligible' => $this->eligible,
            'ineligibility_reason' => $this->ineligibilityReason,
            'collection_mode' => $this->collectionMode,
            'required_history' => $this->requiredHistory,
            'reporting_grain' => $this->reportingGrain,
            'timezone_source' => $this->timezoneSource,
            'safe_collection_lag_days' => $this->safeCollectionLagDays,
            'current_period_collectable' => $this->currentPeriodCollectable,
            'expected_refresh_cadence' => $this->expectedRefreshCadence,
            'incremental_applicable' => $this->incrementalApplicable,
            'late_data_repair_enabled' => $this->lateDataRepairEnabled,
            'late_data_repair' => $this->lateDataRepair,
            'catch_up_enabled' => $this->catchUpEnabled,
            'max_bounded_incremental_span_days' => $this->maxBoundedIncrementalSpanDays,
            'rate_limit_class' => $this->rateLimitClass,
            'cost_class' => $this->costClass,
            'policy_identity' => $this->policyIdentity,
            'policy_version' => $this->policyVersion,
            'policy_fingerprint' => $this->policyFingerprint,
        ];
    }
}
