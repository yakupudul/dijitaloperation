<?php

namespace App\Services\Evidence;

use App\Enums\DataPool\FreshnessState;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\EvidenceEligibilityStatus;
use App\Models\BrandGoal;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DatasetFreshnessEvaluator;
use App\Services\Formulas\FormulaRegistryLoader;
use App\Services\Ga4\Ga4UiDatasetGate;
use App\Services\Gsc\GscUiDatasetGate;
use App\Support\Evidence\EvidenceDefinition;
use App\Support\Evidence\EvidenceEligibilityReport;
use App\Support\Evidence\EvidencePeriod;
use Carbon\CarbonImmutable;

/**
 * Eligibility gates for canonical Evidence. Ineligible definitions produce 0 Evidence rows.
 */
final class EvidenceEligibilityService
{
    public function __construct(
        private readonly GscUiDatasetGate $gscGate,
        private readonly Ga4UiDatasetGate $ga4Gate,
        private readonly DataFreshnessPolicyLoader $policies,
        private readonly DatasetFreshnessEvaluator $freshness,
        private readonly FormulaRegistryLoader $formulas,
    ) {}

    public function evaluate(
        DigitalAsset $asset,
        EvidenceDefinition $definition,
        EvidencePeriod $period,
        ?int $brandGoalId = null,
        ?int $brandOfferingId = null,
    ): EvidenceEligibilityReport {
        $gates = [
            'provenance_valid' => false,
            'integrity_valid' => false,
            'coverage_sufficient' => false,
            'freshness_known' => false,
            'scope_resolvable' => false,
            'measurement_semantics_known' => false,
            'period_explicit' => false,
        ];

        $periodCheck = $this->assertPeriod($period);
        if ($periodCheck !== null) {
            return $this->fail(EvidenceEligibilityStatus::IneligiblePeriod, $periodCheck, $gates);
        }
        $gates['period_explicit'] = true;

        $measurementCheck = $this->assertFormulas($definition);
        if ($measurementCheck !== null) {
            return $this->fail(EvidenceEligibilityStatus::IneligibleMeasurement, $measurementCheck, $gates);
        }
        $gates['measurement_semantics_known'] = true;

        $scope = $this->resolveScope($asset, $definition, $brandGoalId, $brandOfferingId);
        if ($scope['error'] !== null) {
            return $this->fail(EvidenceEligibilityStatus::IneligibleScope, $scope['error'], $gates, $scope['details']);
        }
        $gates['scope_resolvable'] = true;

        $resourceId = (int) $scope['details']['external_resource_id'];
        $spanStart = $period->previousStart;
        $spanEnd = $period->currentEnd;
        $timezone = is_string($scope['details']['reporting_timezone'] ?? null)
            ? (string) $scope['details']['reporting_timezone']
            : null;

        $readiness = $this->datasetReadiness($definition, $asset->id, $resourceId, $spanStart, $spanEnd, $timezone);

        if (! ($readiness['materialization_exists'] ?? false)) {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleProvenance,
                'no_dataset_materialization',
                $gates,
                $readiness,
            );
        }

        $status = $readiness['materialization_status'] ?? null;
        if (in_array($status, [MaterializationStatus::NotCollected->value, MaterializationStatus::Unavailable->value], true)) {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleProvenance,
                'materialization_not_usable',
                $gates,
                $readiness,
            );
        }

        if (($readiness['last_collected_at'] ?? null) === null) {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleProvenance,
                'collection_provenance_missing',
                $gates,
                $readiness,
            );
        }
        $gates['provenance_valid'] = true;

        if (! ($readiness['integrity_ready'] ?? false)) {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleIntegrity,
                'integrity_not_ready',
                $gates,
                $readiness,
            );
        }
        $gates['integrity_valid'] = true;

        $freshnessState = FreshnessState::tryFrom((string) ($readiness['freshness_state'] ?? ''));
        if ($freshnessState === null || $freshnessState === FreshnessState::Unknown) {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleFreshness,
                'freshness_unknown',
                $gates,
                $readiness,
            );
        }
        if ($freshnessState === FreshnessState::IntegrityBlocked) {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleIntegrity,
                'freshness_integrity_blocked',
                $gates,
                $readiness,
            );
        }
        $gates['freshness_known'] = true;

        if (($readiness['coverage_state'] ?? '') !== 'FULLY_COVERED') {
            return $this->fail(
                EvidenceEligibilityStatus::IneligibleCoverage,
                'coverage_not_full_for_compared_periods',
                $gates,
                $readiness,
            );
        }
        $gates['coverage_sufficient'] = true;

        return new EvidenceEligibilityReport(
            status: EvidenceEligibilityStatus::Eligible,
            reason: 'eligible',
            gates: $gates,
            details: $readiness + $scope['details'],
        );
    }

    /**
     * Derive explicit comparable windows from materialization coverage end.
     */
    public function periodFromCoverageEnd(EvidenceDefinition $definition, ?string $coverageEnd): ?EvidencePeriod
    {
        if ($coverageEnd === null || $coverageEnd === '') {
            return null;
        }

        $days = $definition->defaultPeriodDays;
        $currentEnd = CarbonImmutable::parse($coverageEnd)->startOfDay();
        $currentStart = $currentEnd->subDays($days - 1);
        $previousEnd = $currentStart->subDay();
        $previousStart = $previousEnd->subDays($days - 1);

        return new EvidencePeriod(
            currentStart: $currentStart->toDateString(),
            currentEnd: $currentEnd->toDateString(),
            previousStart: $previousStart->toDateString(),
            previousEnd: $previousEnd->toDateString(),
            lengthDays: $days,
        );
    }

    private function assertPeriod(EvidencePeriod $period): ?string
    {
        foreach ([$period->currentStart, $period->currentEnd, $period->previousStart, $period->previousEnd] as $date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return 'period_dates_must_be_iso';
            }
        }

        if ($period->lengthDays < 1) {
            return 'period_length_invalid';
        }

        if ($period->previousEnd >= $period->currentStart) {
            return 'periods_must_not_overlap';
        }

        $currentLen = CarbonImmutable::parse($period->currentStart)->diffInDays(CarbonImmutable::parse($period->currentEnd)) + 1;
        $previousLen = CarbonImmutable::parse($period->previousStart)->diffInDays(CarbonImmutable::parse($period->previousEnd)) + 1;
        if ((int) $currentLen !== $period->lengthDays || (int) $previousLen !== $period->lengthDays) {
            return 'period_lengths_must_match';
        }

        return null;
    }

    private function assertFormulas(EvidenceDefinition $definition): ?string
    {
        try {
            foreach ($definition->formulaIds as $formulaId) {
                $this->formulas->formula($formulaId);
            }
        } catch (\Throwable) {
            return 'formula_not_in_registry';
        }

        return null;
    }

    /**
     * @return array{error: ?string, details: array<string, mixed>}
     */
    private function resolveScope(
        DigitalAsset $asset,
        EvidenceDefinition $definition,
        ?int $brandGoalId,
        ?int $brandOfferingId,
    ): array {
        $asset->loadMissing('brand');
        if ($asset->brand === null) {
            return ['error' => 'brand_missing', 'details' => []];
        }

        $binding = CoreAssetBinding::query()
            ->with('externalResource')
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $definition->bindingCapability)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $binding instanceof CoreAssetBinding) {
            return ['error' => 'binding_missing', 'details' => []];
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return ['error' => 'external_resource_missing', 'details' => []];
        }

        if ($resource->resource_type !== $definition->resourceType) {
            return ['error' => 'resource_type_mismatch', 'details' => []];
        }

        if ($brandGoalId !== null) {
            $goal = BrandGoal::query()->where('id', $brandGoalId)->where('brand_id', $asset->brand_id)->first();
            if (! $goal instanceof BrandGoal) {
                return ['error' => 'goal_not_in_brand', 'details' => []];
            }
        }

        if ($brandOfferingId !== null) {
            $offering = BrandOffering::query()->where('id', $brandOfferingId)->where('brand_id', $asset->brand_id)->first();
            if (! $offering instanceof BrandOffering) {
                return ['error' => 'offering_not_in_brand', 'details' => []];
            }
        }

        $timezone = is_array($resource->metadata) ? ($resource->metadata['reporting_timezone'] ?? null) : null;

        return [
            'error' => null,
            'details' => [
                'brand_id' => $asset->brand_id,
                'external_resource_id' => $resource->id,
                'binding_id' => $binding->id,
                'reporting_timezone' => is_string($timezone) ? $timezone : null,
                'brand_goal_id' => $brandGoalId,
                'brand_offering_id' => $brandOfferingId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetReadiness(
        EvidenceDefinition $definition,
        int $digitalAssetId,
        int $externalResourceId,
        string $start,
        string $end,
        ?string $timezone,
    ): array {
        $gate = match ($definition->provider) {
            'SEARCH_CONSOLE' => $this->gscGate->evaluate($digitalAssetId, $externalResourceId, $definition->datasetId, $start, $end, $timezone),
            'GA4' => $this->ga4Gate->evaluate($digitalAssetId, $externalResourceId, $definition->datasetId, $start, $end, $timezone),
            default => null,
        };

        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $definition->datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->first();

        if ($gate === null) {
            $policy = $this->policies->policy($definition->datasetId) ?? [];
            $evaluation = $this->freshness->evaluate($policy, $materialization, [
                'authorization_ready' => true,
                'integrity_blocked' => false,
                'reporting_timezone' => $timezone,
            ]);

            return [
                'materialization_exists' => $materialization !== null,
                'materialization_status' => $materialization?->status?->value,
                'last_collected_at' => $materialization?->last_collected_at?->toIso8601String(),
                'collection_run_id' => $materialization?->last_successful_collection_run_id,
                'integrity_ready' => false,
                'integrity_status' => 'UNVERIFIED',
                'freshness_state' => $evaluation->state->value,
                'coverage_state' => 'NOT_COVERED',
            ];
        }

        return [
            'materialization_exists' => $gate->materializationExists,
            'materialization_status' => $materialization?->status?->value,
            'last_collected_at' => $materialization?->last_collected_at?->toIso8601String(),
            'collection_run_id' => $materialization?->last_successful_collection_run_id,
            'materialization_id' => $materialization?->id,
            'integrity_ready' => $gate->integrityReady,
            'integrity_status' => $gate->integrityStatus,
            'integrity_audit_run_uuid' => $gate->integrityAuditRunUuid,
            'freshness_state' => $gate->freshnessState,
            'coverage_state' => $gate->coverageState,
            'coverage_dates' => $gate->coveredDates,
        ];
    }

    /**
     * @param  array<string, bool>  $gates
     * @param  array<string, mixed>  $details
     */
    private function fail(
        EvidenceEligibilityStatus $status,
        string $reason,
        array $gates,
        array $details = [],
    ): EvidenceEligibilityReport {
        return new EvidenceEligibilityReport($status, $reason, $gates, $details);
    }
}
