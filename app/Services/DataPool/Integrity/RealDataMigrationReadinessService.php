<?php

namespace App\Services\DataPool\Integrity;

use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MigrationReadinessStatus;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;

/**
 * Deterministic dataset/provider readiness gate for future Prompt 28–31 migrations.
 * No provider calls. No numeric score.
 */
final class RealDataMigrationReadinessService
{
    public function __construct(
        private readonly DataIntegrityRegistryLoader $registry,
    ) {}

    /**
     * @param  list<IntegrityCheckOutcome>  $outcomes
     * @param  list<string>|null  $providers
     * @return array<string, array<string, mixed>>
     */
    public function evaluateProviders(array $outcomes, ?array $providers = null): array
    {
        $providers ??= ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS', 'META_ADS'];
        $out = [];
        foreach ($providers as $provider) {
            $out[$provider] = $this->evaluateProvider($provider, $outcomes);
        }

        $out['_global'] = [
            'all_providers_ready' => collect($out)
                ->filter(fn ($_, $key) => $key !== '_global')
                ->every(fn (array $row): bool => MigrationReadinessStatus::from($row['status'])->allowsRealUiMigration()),
            'numeric_quality_score' => null,
        ];

        return $out;
    }

    /**
     * @param  list<IntegrityCheckOutcome>  $outcomes
     * @return array<string, mixed>
     */
    public function evaluateProvider(string $provider, array $outcomes): array
    {
        $providerOutcomes = array_values(array_filter(
            $outcomes,
            static fn (IntegrityCheckOutcome $o): bool => $o->providerOrSource === $provider,
        ));

        $datasets = [];
        foreach ($providerOutcomes as $outcome) {
            if ($outcome->datasetId === null) {
                continue;
            }
            $datasets[$outcome->datasetId] ??= [];
            $datasets[$outcome->datasetId][] = $outcome;
        }

        $datasetStatuses = [];
        $blockers = [];
        $limitations = [];
        foreach ($datasets as $datasetId => $checks) {
            $status = $this->evaluateDatasetChecks($checks);
            $datasetStatuses[$datasetId] = $status->value;
            if (! $status->allowsRealUiMigration()) {
                $blockers[] = [
                    'dataset_id' => $datasetId,
                    'status' => $status->value,
                    'reasons' => array_values(array_map(
                        static fn (IntegrityCheckOutcome $o): string => (string) $o->message,
                        array_filter(
                            $checks,
                            static fn (IntegrityCheckOutcome $o): bool => $o->blocksMigration && (
                                $o->status === IntegrityCheckStatus::Fail
                                || $o->status === IntegrityCheckStatus::Unverified
                                || ($o->status === IntegrityCheckStatus::Warning && $o->blocksMigration)
                            ),
                        ),
                    )),
                ];
            }
            foreach ($checks as $check) {
                if ($check->status === IntegrityCheckStatus::PassWithLimitation) {
                    $limitations[] = [
                        'dataset_id' => $datasetId,
                        'check_id' => $check->checkId,
                        'message' => $check->message,
                    ];
                }
            }
        }

        $providerStatus = $this->rollUp($datasetStatuses, $limitations !== [], $providerOutcomes === []);

        return [
            'status' => $providerStatus->value,
            'allows_real_ui_migration' => $providerStatus->allowsRealUiMigration(),
            'blocking_datasets' => $blockers,
            'limitations' => $limitations,
            'dataset_statuses' => $datasetStatuses,
            'numeric_quality_score' => null,
        ];
    }

    /**
     * Query last audit for a dataset readiness answer.
     *
     * @return array<string, mixed>
     */
    public function datasetReadiness(?DataIntegrityAuditRun $run, string $provider, string $datasetId): array
    {
        if (! $run instanceof DataIntegrityAuditRun) {
            return [
                'status' => MigrationReadinessStatus::Unverified->value,
                'ready' => false,
                'reasons' => ['No integrity audit run available.'],
                'numeric_quality_score' => null,
            ];
        }

        $checks = $run->checkResults()
            ->where('provider_or_source', $provider)
            ->where('dataset_id', $datasetId)
            ->get()
            ->map(fn ($row) => new IntegrityCheckOutcome(
                checkId: $row->check_id,
                category: $row->category,
                status: $row->status,
                severity: $row->severity,
                message: $row->message,
                expected: $row->expected,
                observed: $row->observed,
                difference: $row->difference,
                tolerance: $row->tolerance,
                evidence: $row->evidence,
                blocksMigration: (bool) $row->blocks_migration,
                providerOrSource: $row->provider_or_source,
                datasetId: $row->dataset_id,
                digitalAssetId: $row->digital_asset_id,
                externalResourceId: $row->external_resource_id,
            ))
            ->all();

        if ($checks === []) {
            return [
                'status' => MigrationReadinessStatus::Unverified->value,
                'ready' => false,
                'reasons' => ["Dataset [{$datasetId}] was not included in audit {$run->uuid}."],
                'numeric_quality_score' => null,
            ];
        }

        $status = $this->evaluateDatasetChecks($checks);

        return [
            'status' => $status->value,
            'ready' => $status->allowsRealUiMigration(),
            'reasons' => array_values(array_map(
                static fn (IntegrityCheckOutcome $o): string => (string) $o->message,
                array_filter($checks, static fn (IntegrityCheckOutcome $o): bool => $o->blocksMigration && $o->status->isBlocking()),
            )),
            'audit_run_uuid' => $run->uuid,
            'numeric_quality_score' => null,
        ];
    }

    /**
     * @param  list<IntegrityCheckOutcome>  $checks
     */
    public function evaluateDatasetChecks(array $checks): MigrationReadinessStatus
    {
        $hasFail = false;
        $hasUnverified = false;
        $hasStale = false;
        $hasPartial = false;
        $hasContract = false;
        $hasLimitation = false;

        foreach ($checks as $check) {
            if ($check->status === IntegrityCheckStatus::Fail && $check->blocksMigration) {
                $hasFail = true;
                if ($check->category === 'contract') {
                    $hasContract = true;
                }
            }
            if ($check->status === IntegrityCheckStatus::Unverified && $check->blocksMigration) {
                $hasUnverified = true;
            }
            if ($check->checkId === 'freshness' && $check->blocksMigration && $check->status === IntegrityCheckStatus::Warning) {
                $hasStale = true;
            }
            if ($check->checkId === 'coverage_intervals' && $check->blocksMigration && $check->status === IntegrityCheckStatus::Warning) {
                $hasPartial = true;
            }
            if ($check->status === IntegrityCheckStatus::PassWithLimitation) {
                $hasLimitation = true;
            }
        }

        if ($hasContract) {
            return MigrationReadinessStatus::BlockedContract;
        }
        if ($hasFail) {
            return MigrationReadinessStatus::BlockedIntegrity;
        }
        if ($hasUnverified) {
            return MigrationReadinessStatus::Unverified;
        }
        if ($hasPartial) {
            return MigrationReadinessStatus::BlockedPartial;
        }
        if ($hasStale) {
            return MigrationReadinessStatus::BlockedStale;
        }
        if ($hasLimitation) {
            return MigrationReadinessStatus::ReadyWithProviderLimitation;
        }

        return MigrationReadinessStatus::ReadyForRealUi;
    }

    /**
     * @param  array<string, string>  $datasetStatuses
     * @param  list<IntegrityCheckOutcome>  $providerOutcomes
     */
    private function rollUp(array $datasetStatuses, bool $hasLimitation, bool $empty): MigrationReadinessStatus
    {
        if ($empty || $datasetStatuses === []) {
            return MigrationReadinessStatus::Unverified;
        }

        $statuses = array_map(
            static fn (string $s): MigrationReadinessStatus => MigrationReadinessStatus::from($s),
            $datasetStatuses,
        );

        foreach ($statuses as $status) {
            if ($status === MigrationReadinessStatus::BlockedContract) {
                return MigrationReadinessStatus::BlockedContract;
            }
        }
        foreach ($statuses as $status) {
            if ($status === MigrationReadinessStatus::BlockedIntegrity) {
                return MigrationReadinessStatus::BlockedIntegrity;
            }
        }
        foreach ($statuses as $status) {
            if ($status === MigrationReadinessStatus::Unverified) {
                return MigrationReadinessStatus::Unverified;
            }
        }
        foreach ($statuses as $status) {
            if ($status === MigrationReadinessStatus::BlockedPartial) {
                return MigrationReadinessStatus::BlockedPartial;
            }
        }
        foreach ($statuses as $status) {
            if ($status === MigrationReadinessStatus::BlockedStale) {
                return MigrationReadinessStatus::BlockedStale;
            }
        }

        if ($hasLimitation || in_array(MigrationReadinessStatus::ReadyWithProviderLimitation, $statuses, true)) {
            return MigrationReadinessStatus::ReadyWithProviderLimitation;
        }

        return MigrationReadinessStatus::ReadyForRealUi;
    }
}
