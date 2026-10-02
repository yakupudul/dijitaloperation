<?php

namespace App\Services\Ga4;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Integrity\RealDataMigrationReadinessService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;
use App\Services\Ga4\Support\Ga4DatasetReadiness;

/**
 * UI-facing readiness/coverage gate for GA4 datasets (Prompt 28). "Rows exist in
 * ga4_* tables" is never treated as ready on its own — every field the UI renders
 * must clear: (1) a DatasetMaterialization exists, (2) the latest integrity audit
 * for this exact asset+resource+dataset is READY, (3) coverage for the requested
 * range is proven via successful_coverage_dates (not MAX(reporting_date) alone),
 * (4) freshness is evaluated (informational — Stale does not block REAL).
 */
final class Ga4UiDatasetGate
{
    public const string PROVIDER = 'GA4';

    public function __construct(
        private readonly RealDataMigrationReadinessService $readinessService,
        private readonly DataFreshnessPolicyLoader $policyLoader,
        private readonly DatasetFreshnessEvaluator $freshnessEvaluator,
    ) {}

    /**
     * Snapshot-mode readiness (e.g. ga4_property_metadata) — no date range coverage,
     * just materialization + integrity + freshness against the current-state row.
     */
    public function evaluateSnapshot(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        ?string $reportingTimezone = null,
    ): Ga4DatasetReadiness {
        $materialization = $this->materialization($digitalAssetId, $externalResourceId, $datasetId);
        $integrity = $this->evaluateIntegrity($digitalAssetId, $externalResourceId, $datasetId);
        $freshness = $this->evaluateFreshness($datasetId, $materialization, $integrity['ready'], $reportingTimezone);

        $covered = $materialization !== null
            && $materialization->last_collected_at !== null
            && ! in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true);

        return new Ga4DatasetReadiness(
            datasetId: $datasetId,
            integrityReady: $integrity['ready'],
            integrityStatus: $integrity['status'],
            integrityAuditRunUuid: $integrity['audit_run_uuid'],
            freshnessState: $freshness,
            coverageState: $covered ? Ga4DatasetReadiness::COVERAGE_FULLY_COVERED : Ga4DatasetReadiness::COVERAGE_NOT_COVERED,
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
    ): Ga4DatasetReadiness {
        $materialization = $this->materialization($digitalAssetId, $externalResourceId, $datasetId);
        $integrity = $this->evaluateIntegrity($digitalAssetId, $externalResourceId, $datasetId);
        $freshness = $this->evaluateFreshness($datasetId, $materialization, $integrity['ready'], $reportingTimezone);
        $coverage = $this->evaluateCoverage($materialization, $start, $end);

        return new Ga4DatasetReadiness(
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

    private function materialization(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
    ): ?DatasetMaterialization {
        return DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->first();
    }

    /**
     * @return array{ready: bool, status: string, audit_run_uuid: ?string}
     */
    private function evaluateIntegrity(int $digitalAssetId, int $externalResourceId, string $datasetId): array
    {
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
            return [
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
            return [
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
     * @return array{state: string, dates: list<string>, effective_start: ?string, effective_end: ?string}
     */
    private function evaluateCoverage(?DatasetMaterialization $materialization, string $start, string $end): array
    {
        $none = [
            'state' => Ga4DatasetReadiness::COVERAGE_NOT_COVERED,
            'dates' => [],
            'effective_start' => null,
            'effective_end' => null,
        ];

        if ($materialization === null) {
            return $none;
        }

        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];

        $dates = is_array($meta['successful_coverage_dates'] ?? null)
            ? array_values(array_filter($meta['successful_coverage_dates'], 'is_string'))
            : [];

        if (is_array($meta['zero_row_success_dates'] ?? null)) {
            $dates = array_merge($dates, array_values(array_filter($meta['zero_row_success_dates'], 'is_string')));
        }

        $dates = array_values(array_unique($dates));

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
                ? Ga4DatasetReadiness::COVERAGE_FULLY_COVERED
                : Ga4DatasetReadiness::COVERAGE_PARTIALLY_COVERED,
            'dates' => $inRange,
            'effective_start' => $inRange[0],
            'effective_end' => $inRange[count($inRange) - 1],
        ];
    }
}
