<?php

namespace App\Services\DataPool\Integrity;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\Integrity\Support\IntegrityAuditRequest;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Provider-neutral data-pool integrity auditor.
 * Verification only — never repairs, deletes, zero-fills, or rebuckets facts.
 */
final class DataPoolIntegrityAuditor
{
    public function __construct(
        private readonly DataIntegrityRegistryLoader $integrityRegistry,
        private readonly DataContractRegistryLoader $dataContracts,
        private readonly DataPoolStorageRegistry $storage,
        private readonly DatasetIntegrityChecker $checker,
        private readonly RealDataMigrationReadinessService $readiness,
    ) {}

    public function run(IntegrityAuditRequest $request): DataIntegrityAuditRun
    {
        if ($request->mode === IntegrityAuditMode::ProviderReconciliation
            && ! (bool) config('moxdop-data-integrity.allow_provider_reconciliation', false)
        ) {
            throw new InvalidArgumentException(
                'Provider reconciliation mode is opt-in only and is disabled in this environment.'
            );
        }

        $this->dataContracts->load();
        $this->integrityRegistry->registry();

        $run = DataIntegrityAuditRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => IntegrityAuditStatus::Running,
            'mode' => $request->mode,
            'scope_type' => 'production_datasets',
            'scope' => [
                'providers' => $request->providers,
                'dataset_ids' => $request->datasetIds,
                'digital_asset_ids' => $request->digitalAssetIds,
                'external_resource_ids' => $request->externalResourceIds,
                'date_from' => $request->dateFrom,
                'date_to' => $request->dateTo,
            ],
            'initiated_by_user_id' => $request->initiatedBy?->id,
            'contract_registry_version' => $this->dataContracts->version(),
            'storage_contract_version' => (int) ($this->storage->metadata()['version'] ?? 1),
            'formula_registry_version' => (int) ($this->storage->metadata()['formula_registry_version'] ?? 1),
            'integrity_registry_version' => $this->integrityRegistry->version(),
            'audit_rules_version' => (int) config('moxdop-data-integrity.audit_rules_version', 1),
            'started_at' => now(),
            'metadata' => [
                'provider_calls' => 0,
                'automatic_repair' => false,
                'numeric_quality_score' => null,
            ],
        ]);

        try {
            $outcomes = $this->collectOutcomes($request);
            if ($request->persistResults) {
                $this->persistOutcomes($run, $outcomes);
            }

            $counts = $this->countStatuses($outcomes);
            $providerReadiness = $this->readiness->evaluateProviders($outcomes, $request->providers);

            $run->forceFill([
                'status' => IntegrityAuditStatus::Completed,
                'completed_at' => now(),
                'checks_total' => array_sum($counts),
                'checks_pass' => $counts[IntegrityCheckStatus::Pass->value] ?? 0,
                'checks_pass_with_limitation' => $counts[IntegrityCheckStatus::PassWithLimitation->value] ?? 0,
                'checks_warning' => $counts[IntegrityCheckStatus::Warning->value] ?? 0,
                'checks_fail' => $counts[IntegrityCheckStatus::Fail->value] ?? 0,
                'checks_unverified' => $counts[IntegrityCheckStatus::Unverified->value] ?? 0,
                'checks_not_applicable' => $counts[IntegrityCheckStatus::NotApplicable->value] ?? 0,
                'provider_readiness' => $providerReadiness,
                'summary' => [
                    'profiles_audited' => count($this->profilesForRequest($request)),
                    'blocking_failures' => count(array_filter(
                        $outcomes,
                        static fn (IntegrityCheckOutcome $o): bool => $o->blocksMigration && $o->status->isBlocking(),
                    )),
                    'real_pool_fact_rows_observed' => $this->estimateFactScale($outcomes),
                    'mode' => $request->mode->value,
                ],
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => IntegrityAuditStatus::Failed,
                'completed_at' => now(),
                'summary' => ['error' => 'Audit failed without exposing secrets'],
                'metadata' => array_merge($run->metadata ?? [], [
                    'error_class' => $e::class,
                ]),
            ])->save();
            Log::error('data_pool.integrity.audit_failed', [
                'audit_run_id' => $run->id,
                'error_class' => $e::class,
            ]);
            throw $e;
        }

        Log::info('data_pool.integrity.audit_completed', [
            'audit_run_uuid' => $run->uuid,
            'checks_total' => $run->checks_total,
            'checks_fail' => $run->checks_fail,
            // Never log tokens or customer metric dumps.
        ]);

        return $run->fresh(['checkResults']) ?? $run;
    }

    /**
     * @return list<IntegrityCheckOutcome>
     */
    public function collectOutcomes(IntegrityAuditRequest $request): array
    {
        $outcomes = [];
        $profiles = $this->profilesForRequest($request);
        $scopes = $this->resolveScopes($request);

        if ($scopes === []) {
            // Registry-level audit without bound resources (inventory / contract checks).
            foreach ($profiles as $profile) {
                $outcomes = array_merge(
                    $outcomes,
                    $this->checker->checkProfile(
                        $profile,
                        null,
                        null,
                        $request->dateFrom,
                        $request->dateTo,
                    ),
                );
            }

            return $outcomes;
        }

        foreach ($scopes as $scope) {
            foreach ($profiles as $profile) {
                if ($request->providers !== null
                    && ! in_array((string) $profile['provider_or_source'], $request->providers, true)
                ) {
                    continue;
                }
                if ($request->datasetIds !== null
                    && ! in_array((string) $profile['dataset_id'], $request->datasetIds, true)
                ) {
                    continue;
                }

                $provider = (string) $profile['provider_or_source'];
                $capability = match ($provider) {
                    'SEARCH_CONSOLE' => 'search_console',
                    'GA4' => 'ga4',
                    'GOOGLE_ADS' => 'google_ads',
                    'META_ADS' => 'meta_ads',
                    default => null,
                };
                if ($capability !== null && ($scope['capability'] ?? null) !== null && $scope['capability'] !== $capability) {
                    continue;
                }

                $outcomes = array_merge(
                    $outcomes,
                    $this->checker->checkProfile(
                        $profile,
                        $scope['digital_asset_id'],
                        $scope['external_resource_id'],
                        $request->dateFrom,
                        $request->dateTo,
                    ),
                );
            }
        }

        return $outcomes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profilesForRequest(IntegrityAuditRequest $request): array
    {
        $profiles = $this->integrityRegistry->profilesForProviders($request->providers);
        if ($request->datasetIds !== null) {
            $profiles = array_values(array_filter(
                $profiles,
                static fn (array $p): bool => in_array((string) $p['dataset_id'], $request->datasetIds, true),
            ));
        }

        return $profiles;
    }

    /**
     * @return list<array{digital_asset_id: int, external_resource_id: int, capability: ?string}>
     */
    private function resolveScopes(IntegrityAuditRequest $request): array
    {
        $query = CoreAssetBinding::query()
            ->with('externalResource')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE);

        if ($request->digitalAssetIds !== null) {
            $query->whereIn('digital_asset_id', $request->digitalAssetIds);
        }
        if ($request->externalResourceIds !== null) {
            $query->whereIn('external_resource_id', $request->externalResourceIds);
        }

        $scopes = [];
        foreach ($query->orderBy('id')->get() as $binding) {
            if (! $binding->externalResource instanceof CoreExternalResource) {
                continue;
            }
            $scopes[] = [
                'digital_asset_id' => (int) $binding->digital_asset_id,
                'external_resource_id' => (int) $binding->external_resource_id,
                'capability' => $binding->capability,
            ];
        }

        return $scopes;
    }

    /**
     * @param  list<IntegrityCheckOutcome>  $outcomes
     */
    private function persistOutcomes(DataIntegrityAuditRun $run, array $outcomes): void
    {
        $rows = [];
        $now = now();
        foreach ($outcomes as $outcome) {
            $rows[] = [
                'audit_run_id' => $run->id,
                'provider_or_source' => $outcome->providerOrSource,
                'digital_asset_id' => $outcome->digitalAssetId,
                'external_resource_id' => $outcome->externalResourceId,
                'dataset_id' => $outcome->datasetId,
                'check_id' => $outcome->checkId,
                'category' => $outcome->category,
                'severity' => $outcome->severity,
                'status' => $outcome->status->value,
                'expected' => $outcome->expected !== null ? json_encode($outcome->expected) : null,
                'observed' => $outcome->observed !== null ? json_encode($outcome->observed) : null,
                'difference' => $outcome->difference !== null ? json_encode($outcome->difference) : null,
                'tolerance' => $outcome->tolerance !== null ? json_encode($outcome->tolerance) : null,
                'message' => $outcome->message !== null ? mb_substr($outcome->message, 0, 500) : null,
                'evidence' => $outcome->evidence !== null ? json_encode($outcome->evidence) : null,
                'blocks_migration' => $outcome->blocksMigration,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DataIntegrityCheckResult::query()->insert($chunk);
        }
    }

    /**
     * @param  list<IntegrityCheckOutcome>  $outcomes
     * @return array<string, int>
     */
    private function countStatuses(array $outcomes): array
    {
        $counts = [];
        foreach (IntegrityCheckStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($outcomes as $outcome) {
            $counts[$outcome->status->value]++;
        }

        return $counts;
    }

    /**
     * @param  list<IntegrityCheckOutcome>  $outcomes
     */
    private function estimateFactScale(array $outcomes): int
    {
        $scale = 0;
        foreach ($outcomes as $outcome) {
            if (isset($outcome->evidence['rows']) && is_numeric($outcome->evidence['rows'])) {
                $scale = max($scale, (int) $outcome->evidence['rows']);
            }
        }

        return $scale;
    }
}
