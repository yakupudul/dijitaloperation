<?php

namespace App\Services\Gsc;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Integrity\RealDataMigrationReadinessService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;
use App\Services\Gsc\Support\GscDatasetReadiness;

/**
 * UI-facing readiness/coverage gate for GSC datasets (Prompt 29).
 *
 * A bound Digital Asset reads both its legacy asset-bound materialization and the
 * provider-resource-first central materialization (digital_asset_id = null) of the
 * bound property. Central coverage only counts dates written by Web search-type
 * dataset runs, because the bound read path serves Web performance only. A formal
 * binding-scoped integrity audit wins when present; otherwise a durable central
 * materialization is treated as collection-verified (same rule as Google Ads).
 */
final class GscUiDatasetGate
{
    public const string PROVIDER = 'SEARCH_CONSOLE';

    public function __construct(
        private readonly RealDataMigrationReadinessService $readinessService,
        private readonly DataFreshnessPolicyLoader $policyLoader,
        private readonly DatasetFreshnessEvaluator $freshnessEvaluator,
    ) {}

    /**
     * Snapshot-mode readiness (e.g. gsc_sitemap_snapshot, gsc_url_inspection_snapshot).
     */
    public function evaluateSnapshot(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        ?string $reportingTimezone = null,
    ): GscDatasetReadiness {
        $scope = $this->materializations($digitalAssetId, $externalResourceId, $datasetId);
        $materialization = $this->latestMaterialization($scope);
        $integrity = $this->evaluateIntegrity($digitalAssetId, $externalResourceId, $datasetId, $scope['central']);
        $freshness = $this->evaluateFreshness($datasetId, $materialization, $integrity['ready'], $reportingTimezone);

        $covered = $this->isCollected($scope['bound']) || $this->isCollected($scope['central']);

        return new GscDatasetReadiness(
            datasetId: $datasetId,
            integrityReady: $integrity['ready'],
            integrityStatus: $integrity['status'],
            integrityAuditRunUuid: $integrity['audit_run_uuid'],
            freshnessState: $freshness,
            coverageState: $covered ? GscDatasetReadiness::COVERAGE_FULLY_COVERED : GscDatasetReadiness::COVERAGE_NOT_COVERED,
            coveredDates: [],
            effectiveStart: null,
            effectiveEnd: null,
            materializationExists: $materialization !== null,
        );
    }

    /**
     * Historical-incremental readiness for a requested [start, end] range.
     */
    public function evaluate(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        string $start,
        string $end,
        ?string $reportingTimezone = null,
    ): GscDatasetReadiness {
        $scope = $this->materializations($digitalAssetId, $externalResourceId, $datasetId);
        $materialization = $this->latestMaterialization($scope);
        $integrity = $this->evaluateIntegrity($digitalAssetId, $externalResourceId, $datasetId, $scope['central']);
        $freshness = $this->evaluateFreshness($datasetId, $materialization, $integrity['ready'], $reportingTimezone);
        $coverage = $this->evaluateCoverage($this->coverageDates($scope), $start, $end);

        return new GscDatasetReadiness(
            datasetId: $datasetId,
            integrityReady: $integrity['ready'],
            integrityStatus: $integrity['status'],
            integrityAuditRunUuid: $integrity['audit_run_uuid'],
            freshnessState: $freshness,
            coverageState: $coverage['state'],
            coveredDates: $coverage['dates'],
            effectiveStart: $coverage['effective_start'],
            effectiveEnd: $coverage['effective_end'],
            materializationExists: $materialization !== null,
        );
    }

    /**
     * @return array{bound: ?DatasetMaterialization, central: ?DatasetMaterialization}
     */
    private function materializations(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
    ): array {
        $base = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('external_resource_id', $externalResourceId);

        return [
            'bound' => (clone $base)
                ->where('digital_asset_id', $digitalAssetId)
                ->orderByDesc('last_collected_at')
                ->first(),
            'central' => (clone $base)
                ->whereNull('digital_asset_id')
                ->orderByDesc('last_collected_at')
                ->first(),
        ];
    }

    /**
     * The most recently collected scope drives freshness.
     *
     * @param  array{bound: ?DatasetMaterialization, central: ?DatasetMaterialization}  $scope
     */
    private function latestMaterialization(array $scope): ?DatasetMaterialization
    {
        $bound = $scope['bound'];
        $central = $scope['central'];
        if ($bound === null || $central === null) {
            return $central ?? $bound;
        }

        return ($central->last_collected_at?->getTimestamp() ?? 0) >= ($bound->last_collected_at?->getTimestamp() ?? 0)
            ? $central
            : $bound;
    }

    private function isCollected(?DatasetMaterialization $materialization): bool
    {
        return $materialization !== null
            && $materialization->last_collected_at !== null
            && ! in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true);
    }

    /**
     * Successful coverage dates (including zero-row success) across the bound scope and
     * the Web search-type runs of the central scope.
     *
     * @param  array{bound: ?DatasetMaterialization, central: ?DatasetMaterialization}  $scope
     * @return list<string>
     */
    private function coverageDates(array $scope): array
    {
        $dates = [];

        if ($scope['bound'] !== null) {
            $meta = is_array($scope['bound']->freshness_metadata) ? $scope['bound']->freshness_metadata : [];
            foreach (['successful_coverage_dates', 'zero_row_success_dates'] as $key) {
                if (is_array($meta[$key] ?? null)) {
                    $dates = array_merge($dates, array_values(array_filter($meta[$key], 'is_string')));
                }
            }
        }

        if ($scope['central'] !== null) {
            $dates = array_merge($dates, $this->centralWebCoverageDates($scope['central']));
        }

        return array_values(array_unique($dates));
    }

    /**
     * Central materializations are shared by every collected search type, so only dates
     * attributed to Web dataset runs prove Web coverage.
     *
     * @return list<string>
     */
    private function centralWebCoverageDates(DatasetMaterialization $materialization): array
    {
        $byRun = data_get($materialization->freshness_metadata, 'coverage_dates_by_dataset_run');
        if (! is_array($byRun) || $byRun === []) {
            return [];
        }

        $webRunIds = CollectionDatasetRun::query()
            ->whereIn('id', array_map('intval', array_keys($byRun)))
            ->get(['id', 'metadata'])
            ->filter(static function (CollectionDatasetRun $run): bool {
                $searchType = data_get($run->metadata, 'search_type')
                    ?? data_get($run->metadata, 'central_definition.search_type')
                    ?? 'web';

                return $searchType === 'web';
            })
            ->map(static fn (CollectionDatasetRun $run): int => (int) $run->id)
            ->all();

        $dates = [];
        foreach ($webRunIds as $runId) {
            $runDates = $byRun[(string) $runId] ?? $byRun[$runId] ?? [];
            if (is_array($runDates)) {
                $dates = array_merge($dates, array_values(array_filter($runDates, 'is_string')));
            }
        }

        return $dates;
    }

    /**
     * @return array{ready: bool, status: string, audit_run_uuid: ?string}
     */
    private function evaluateIntegrity(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        ?DatasetMaterialization $central = null,
    ): array {
        $run = DataIntegrityAuditRun::query()
            ->whereHas('checkResults', function ($query) use ($digitalAssetId, $externalResourceId, $datasetId): void {
                $query->where('digital_asset_id', $digitalAssetId)
                    ->where('external_resource_id', $externalResourceId)
                    ->where('dataset_id', $datasetId)
                    ->where('provider_or_source', self::PROVIDER);
            })
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof DataIntegrityAuditRun) {
            return $this->centralCollectionVerified($central) ?? [
                'ready' => false,
                'status' => 'UNVERIFIED',
                'audit_run_uuid' => null,
            ];
        }

        $checks = DataIntegrityCheckResult::query()
            ->where('audit_run_id', $run->id)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('dataset_id', $datasetId)
            ->where('provider_or_source', self::PROVIDER)
            ->get()
            ->map(static fn (DataIntegrityCheckResult $row): IntegrityCheckOutcome => new IntegrityCheckOutcome(
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
            return $this->centralCollectionVerified($central) ?? [
                'ready' => false,
                'status' => 'UNVERIFIED',
                'audit_run_uuid' => $run->uuid,
            ];
        }

        $status = $this->readinessService->evaluateDatasetChecks($checks);

        return [
            'ready' => $status->allowsRealUiMigration(),
            'status' => $status->value,
            'audit_run_uuid' => $run->uuid,
        ];
    }

    /**
     * Central typed writes have already passed contract normalization and a durable
     * storage commit; integrity audits are binding-scoped and never cover them. Coverage
     * and freshness are still evaluated independently, so nothing is fabricated here.
     *
     * @return array{ready: bool, status: string, audit_run_uuid: ?string}|null
     */
    private function centralCollectionVerified(?DatasetMaterialization $central): ?array
    {
        if (! $this->isCollected($central)) {
            return null;
        }

        return [
            'ready' => true,
            'status' => 'CENTRAL_COLLECTION_VERIFIED',
            'audit_run_uuid' => null,
        ];
    }

    private function evaluateFreshness(
        string $datasetId,
        ?DatasetMaterialization $materialization,
        bool $integrityReady,
        ?string $reportingTimezone,
    ): string {
        $policy = $this->policyLoader->policy($datasetId) ?? [];

        $evaluation = $this->freshnessEvaluator->evaluate($policy, $materialization, [
            'authorization_ready' => true,
            'integrity_blocked' => ! $integrityReady,
            'reporting_timezone' => $reportingTimezone,
        ]);

        return $evaluation->state->value;
    }

    /**
     * @param  list<string>  $dates
     * @return array{state: string, dates: list<string>, effective_start: ?string, effective_end: ?string}
     */
    private function evaluateCoverage(array $dates, string $start, string $end): array
    {
        $none = [
            'state' => GscDatasetReadiness::COVERAGE_NOT_COVERED,
            'dates' => [],
            'effective_start' => null,
            'effective_end' => null,
        ];

        if ($dates === []) {
            return $none;
        }

        $inRange = array_values(array_filter($dates, static fn (string $d): bool => $d >= $start && $d <= $end));

        if ($inRange === []) {
            return $none;
        }

        sort($inRange);

        $gaps = CoverageIntervalSet::fromSuccessfulDates($dates)->gapsIn($start, $end);

        return [
            'state' => $gaps === []
                ? GscDatasetReadiness::COVERAGE_FULLY_COVERED
                : GscDatasetReadiness::COVERAGE_PARTIALLY_COVERED,
            'dates' => $inRange,
            'effective_start' => $inRange[0],
            'effective_end' => $inRange[count($inRange) - 1],
        ];
    }
}
