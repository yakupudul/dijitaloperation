<?php

namespace App\Services\GoogleAds;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\DataPool\Integrity\RealDataMigrationReadinessService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;
use App\Services\GoogleAds\Support\GoogleAdsDatasetReadiness;

/**
 * UI-facing readiness/coverage gate for Google Ads datasets.
 *
 * Central provider-resource-first materializations are canonical. Asset-bound
 * materializations remain a migration fallback. A formal asset-bound integrity audit
 * wins when available; otherwise a successfully typed central materialization is
 * trusted as collection-verified rather than hidden from the Digital Asset UI.
 */
final class GoogleAdsUiDatasetGate
{
    public const string PROVIDER = 'GOOGLE_ADS';

    public function __construct(
        private readonly RealDataMigrationReadinessService $readinessService,
        private readonly DataFreshnessPolicyLoader $policyLoader,
        private readonly DatasetFreshnessEvaluator $freshnessEvaluator,
    ) {}

    public function evaluateSnapshot(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        ?string $reportingTimezone = null,
    ): GoogleAdsDatasetReadiness {
        $materialization = $this->materialization($digitalAssetId, $externalResourceId, $datasetId);
        $integrity = $this->evaluateIntegrity($digitalAssetId, $externalResourceId, $datasetId, $materialization);
        $freshness = $this->evaluateFreshness($datasetId, $materialization, $integrity['ready'], $reportingTimezone);

        $covered = $materialization !== null
            && $materialization->last_collected_at !== null
            && ! in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true);

        return new GoogleAdsDatasetReadiness(
            datasetId: $datasetId,
            integrityReady: $integrity['ready'],
            integrityStatus: $integrity['status'],
            integrityAuditRunUuid: $integrity['audit_run_uuid'],
            freshnessState: $freshness,
            coverageState: $covered ? GoogleAdsDatasetReadiness::COVERAGE_FULLY_COVERED : GoogleAdsDatasetReadiness::COVERAGE_NOT_COVERED,
            coveredDates: [],
            effectiveStart: null,
            effectiveEnd: null,
            materializationExists: $materialization !== null,
        );
    }

    public function evaluate(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        string $start,
        string $end,
        ?string $reportingTimezone = null,
    ): GoogleAdsDatasetReadiness {
        $materialization = $this->materialization($digitalAssetId, $externalResourceId, $datasetId);
        $integrity = $this->evaluateIntegrity($digitalAssetId, $externalResourceId, $datasetId, $materialization);
        $freshness = $this->evaluateFreshness($datasetId, $materialization, $integrity['ready'], $reportingTimezone);
        $coverage = $this->evaluateCoverage($materialization, $start, $end);

        return new GoogleAdsDatasetReadiness(
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

    private function materialization(int $digitalAssetId, int $externalResourceId, string $datasetId): ?DatasetMaterialization
    {
        $base = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('provider_or_source', self::PROVIDER);

        $central = (clone $base)
            ->whereNull('digital_asset_id')
            ->orderByDesc('last_collected_at')
            ->first();
        if ($central instanceof DatasetMaterialization) {
            return $central;
        }

        return $base
            ->where('digital_asset_id', $digitalAssetId)
            ->orderByDesc('last_collected_at')
            ->first();
    }

    /** @return array{ready:bool,status:string,audit_run_uuid:?string} */
    private function evaluateIntegrity(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        ?DatasetMaterialization $materialization,
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

        if ($run instanceof DataIntegrityAuditRun) {
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

            if ($checks !== []) {
                $status = $this->readinessService->evaluateDatasetChecks($checks);

                return [
                    'ready' => $status->allowsRealUiMigration(),
                    'status' => $status->value,
                    'audit_run_uuid' => $run->uuid,
                ];
            }
        }

        // Central typed writes have already passed contract normalization + storage
        // commit. Treat that durable provider materialization as collection-verified
        // until a formal asset-level integrity audit exists. This does not fabricate
        // coverage; coverage/freshness are still evaluated independently below.
        if ($materialization instanceof DatasetMaterialization
            && $materialization->digital_asset_id === null
            && $materialization->last_collected_at !== null
            && ! in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true)) {
            return [
                'ready' => true,
                'status' => 'CENTRAL_COLLECTION_VERIFIED',
                'audit_run_uuid' => null,
            ];
        }

        return [
            'ready' => false,
            'status' => 'UNVERIFIED',
            'audit_run_uuid' => $run?->uuid,
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

    /** @return array{state:string,dates:list<string>,effective_start:?string,effective_end:?string} */
    private function evaluateCoverage(?DatasetMaterialization $materialization, string $start, string $end): array
    {
        $none = [
            'state' => GoogleAdsDatasetReadiness::COVERAGE_NOT_COVERED,
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
            'state' => $gaps === [] ? GoogleAdsDatasetReadiness::COVERAGE_FULLY_COVERED : GoogleAdsDatasetReadiness::COVERAGE_PARTIALLY_COVERED,
            'dates' => $inRange,
            'effective_start' => $inRange[0],
            'effective_end' => $inRange[count($inRange) - 1],
        ];
    }
}
