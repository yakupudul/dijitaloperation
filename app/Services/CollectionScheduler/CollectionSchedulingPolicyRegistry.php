<?php

namespace App\Services\CollectionScheduler;

use App\Services\Collection\DataContractRegistryLoader;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Support\CollectionScheduler\CollectionSchedulingPolicy;

/**
 * Code-owned Collection Scheduling Policy Registry (Prompt 62).
 * Reuses Prompt 7 Data Contract + Prompt 27 Freshness Policy — no duplicate magic constants.
 */
final class CollectionSchedulingPolicyRegistry
{
    private const POLICY_IDENTITY = 'MOXDOP_COLLECTION_SCHEDULING_POLICY';

    /**
     * Capability → provider_or_source (schedulable analytics providers only).
     * DataForSEO / WordPress are intentionally absent from routine scheduling.
     *
     * @var array<string, string>
     */
    public const CAPABILITY_PROVIDER = [
        'ga4' => 'GA4',
        'search_console' => 'SEARCH_CONSOLE',
        'google_ads' => 'GOOGLE_ADS',
        'meta_ads' => 'META_ADS',
    ];

    public function __construct(
        private readonly DataFreshnessPolicyLoader $freshness,
        private readonly DataContractRegistryLoader $contracts,
    ) {}

    public function identity(): string
    {
        return self::POLICY_IDENTITY;
    }

    public function version(): int
    {
        $this->freshness->validate();

        return $this->freshness->version();
    }

    public function fingerprint(): string
    {
        $this->freshness->validate();

        return hash('sha256', implode('|', [
            self::POLICY_IDENTITY,
            'freshness_v'.$this->freshness->version(),
            'contract_'.$this->contracts->checksum(),
            'freshness_'.$this->freshness->registryId(),
        ]));
    }

    /**
     * @return list<string>
     */
    public function schedulableProviders(): array
    {
        return array_values(array_unique(array_values(self::CAPABILITY_PROVIDER)));
    }

    public function providerForCapability(string $capability): ?string
    {
        return self::CAPABILITY_PROVIDER[$capability] ?? null;
    }

    public function isDataForSeoRoutinelyScheduled(): bool
    {
        return false;
    }

    public function policy(string $datasetId): ?CollectionSchedulingPolicy
    {
        $this->freshness->validate();
        $raw = $this->freshness->policy($datasetId);
        if ($raw === null) {
            return null;
        }

        $provider = (string) ($raw['provider_or_source'] ?? '');
        $resourceType = (string) ($raw['resource_type'] ?? $raw['resource_kind'] ?? '');
        $reprocess = is_array($raw['late_data_reprocessing'] ?? null) ? $raw['late_data_reprocessing'] : [];
        $windowDays = $reprocess['window_days'] ?? null;
        $lateEnabled = ($reprocess['strategy'] ?? '') === 'fixed_recent_reporting_window'
            && is_int($windowDays)
            && $windowDays > 0;

        $history = is_array($raw['contract_history_policy'] ?? null)
            ? $raw['contract_history_policy']
            : [];

        $catchUp = is_array($raw['catch_up_policy'] ?? null) ? $raw['catch_up_policy'] : [];
        $catchUpEnabled = ($catchUp['enabled'] ?? true) !== false;

        $ineligibility = null;
        $eligible = true;
        if (($raw['incremental_applicable'] ?? true) === false) {
            $eligible = false;
            $ineligibility = (string) ($raw['non_applicable_reason'] ?? 'POLICY_NOT_CONFIGURED');
        }

        $policyVersion = (int) ($raw['policy_version'] ?? $this->freshness->version());
        $fingerprintPayload = [
            'dataset_id' => $datasetId,
            'provider' => $provider,
            'mode' => $raw['collection_mode'] ?? null,
            'lag' => $raw['safe_collection_lag_days'] ?? null,
            'cadence' => $raw['expected_refresh_cadence'] ?? null,
            'late' => $reprocess,
            'catch_up' => $catchUp,
            'max_span' => $raw['max_bounded_incremental_span_days'] ?? null,
            'history' => $history,
            'version' => $policyVersion,
        ];

        $lag = $raw['safe_collection_lag_days'] ?? null;
        $maxSpan = $raw['max_bounded_incremental_span_days'] ?? null;

        return new CollectionSchedulingPolicy(
            datasetId: $datasetId,
            providerOrSource: $provider,
            resourceType: $resourceType,
            eligible: $eligible,
            ineligibilityReason: $ineligibility,
            collectionMode: (string) ($raw['collection_mode'] ?? ''),
            requiredHistory: $history,
            reportingGrain: is_array($raw['reporting_grain'] ?? null)
                ? array_values(array_map('strval', $raw['reporting_grain']))
                : (isset($raw['reporting_grain']) ? (string) $raw['reporting_grain'] : null),
            timezoneSource: (string) ($raw['timezone_source'] ?? 'resource_timezone_or_utc'),
            safeCollectionLagDays: is_int($lag) ? $lag : (is_numeric($lag) ? (int) $lag : null),
            currentPeriodCollectable: (bool) ($raw['current_period_collectable'] ?? false),
            expectedRefreshCadence: isset($raw['expected_refresh_cadence'])
                ? (string) $raw['expected_refresh_cadence']
                : null,
            incrementalApplicable: ($raw['incremental_applicable'] ?? true) !== false,
            lateDataRepairEnabled: $lateEnabled,
            lateDataRepair: $reprocess,
            catchUpEnabled: $catchUpEnabled,
            maxBoundedIncrementalSpanDays: is_int($maxSpan) ? $maxSpan : null,
            rateLimitClass: isset($raw['rate_limit_class']) ? (string) $raw['rate_limit_class'] : null,
            costClass: isset($raw['cost_class']) ? (string) $raw['cost_class'] : 'provider_owned_read',
            policyIdentity: self::POLICY_IDENTITY,
            policyVersion: $policyVersion,
            policyFingerprint: hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)),
            raw: $raw,
        );
    }

    public function primaryDatasetForFamily(string $requestFamilyId): ?string
    {
        $this->contracts->load();
        foreach ($this->contracts->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $requestFamilyId) {
                $dataset = $requirement['dataset'] ?? null;
                if (is_string($dataset) && $dataset !== '') {
                    return $dataset;
                }
            }
        }

        return null;
    }
}
