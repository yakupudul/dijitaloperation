<?php

namespace App\Services\Evidence;

use App\Models\DigitalAsset;
use App\Services\Formulas\Ga4FormulaCalculator;
use App\Services\Formulas\GoogleAdsFormulaCalculator;
use App\Services\Formulas\GscFormulaCalculator;
use App\Services\Formulas\MetaAdsFormulaCalculator;
use App\Support\Evidence\EvidenceCandidate;
use App\Support\Evidence\EvidenceDefinition;
use App\Support\Evidence\EvidenceEligibilityReport;
use App\Support\Evidence\EvidencePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds in-memory Evidence candidates from normalized pool facts.
 * Does not copy raw provider payloads. Does not write Evidence.
 */
final class EvidenceCandidateBuilder
{
    public function __construct(
        private readonly GscFormulaCalculator $gscFormulas,
        private readonly Ga4FormulaCalculator $ga4Formulas,
        private readonly GoogleAdsFormulaCalculator $googleAdsFormulas,
        private readonly MetaAdsFormulaCalculator $metaAdsFormulas,
        private readonly EvidenceIdentityFingerprint $fingerprints,
    ) {}

    public function build(
        DigitalAsset $asset,
        EvidenceDefinition $definition,
        EvidencePeriod $period,
        EvidenceEligibilityReport $eligibility,
        ?int $brandGoalId = null,
        ?int $brandOfferingId = null,
    ): EvidenceCandidate {
        $resourceId = (int) $eligibility->details['external_resource_id'];
        $grain = $this->resolveGrain($definition, $asset->id, $resourceId);
        $current = $this->aggregate($definition, $asset->id, $period->currentStart, $period->currentEnd);
        $previous = $this->aggregate($definition, $asset->id, $period->previousStart, $period->previousEnd);

        $metrics = [];
        foreach ($definition->metricFields as $field) {
            $currentValue = $current[$field] ?? 0.0;
            $previousValue = $previous[$field] ?? 0.0;
            $change = $this->relativeChange($definition, $currentValue, $previousValue);
            $metrics[$field] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'relative_change' => $change['value'],
                'relative_change_state' => $change['state'],
                'formula_id' => 'FORMULA_PERIOD_RELATIVE_CHANGE',
            ];
        }

        if ($definition->id === 'gsc.property.period_comparison') {
            $ctrCurrent = $this->gscFormulas->ctr(
                (int) ($current['clicks'] ?? 0),
                (int) ($current['impressions'] ?? 0),
            );
            $ctrPrevious = $this->gscFormulas->ctr(
                (int) ($previous['clicks'] ?? 0),
                (int) ($previous['impressions'] ?? 0),
            );
            $metrics['ctr'] = [
                'current' => $ctrCurrent->isValue() ? $ctrCurrent->value : null,
                'previous' => $ctrPrevious->isValue() ? $ctrPrevious->value : null,
                'current_state' => $ctrCurrent->state,
                'previous_state' => $ctrPrevious->state,
                'formula_id' => 'FORMULA_GSC_CTR',
            ];
        }

        $payload = [
            'definition_id' => $definition->id,
            'statement_kind' => $definition->statementKind,
            'grain' => [$definition->grainColumn => $grain],
            'period' => $period->toArray(),
            'metrics' => $metrics,
            'formula_ids' => $definition->formulaIds,
            'freshness_state' => $eligibility->details['freshness_state'] ?? null,
            'integrity_status' => $eligibility->details['integrity_status'] ?? null,
            'integrity_audit_run_uuid' => $eligibility->details['integrity_audit_run_uuid'] ?? null,
            'provenance' => [
                'dataset_id' => $definition->datasetId,
                'physical_table' => $definition->physicalTable,
                'external_resource_id' => $resourceId,
                'collection_run_id' => $eligibility->details['collection_run_id'] ?? null,
                'materialization_id' => $eligibility->details['materialization_id'] ?? null,
                'current_fact_rows' => $current['_rows'] ?? 0,
                'previous_fact_rows' => $previous['_rows'] ?? 0,
            ],
            'brand_id' => $asset->brand_id,
            'brand_goal_id' => $brandGoalId,
            'brand_offering_id' => $brandOfferingId,
            'generated_by_ai' => false,
            'normalization_version' => EvidenceDefinitionRegistry::VERSION,
        ];

        $currency = $this->resolveCurrency($definition, $asset->id, $resourceId);
        if ($currency !== null) {
            $payload['currency'] = $currency;
        }

        $fingerprintInputs = [
            'definition_id' => $definition->id,
            'digital_asset_id' => $asset->id,
            'grain' => $grain,
            'current_start' => $period->currentStart,
            'current_end' => $period->currentEnd,
            'previous_start' => $period->previousStart,
            'previous_end' => $period->previousEnd,
            'brand_goal_id' => $brandGoalId,
            'brand_offering_id' => $brandOfferingId,
            'normalization_version' => EvidenceDefinitionRegistry::VERSION,
        ];

        return new EvidenceCandidate(
            definition: $definition,
            asset: $asset,
            period: $period,
            title: $definition->titleTemplate,
            payload: $payload,
            fingerprintInputs: $fingerprintInputs,
            externalResourceId: $resourceId,
            collectionRunId: isset($eligibility->details['collection_run_id'])
                ? (int) $eligibility->details['collection_run_id']
                : null,
            brandGoalId: $brandGoalId,
            brandOfferingId: $brandOfferingId,
            observedAt: CarbonImmutable::now(),
        );
    }

    public function fingerprintFor(EvidenceCandidate $candidate): string
    {
        return $this->fingerprints->make($candidate->fingerprintInputs);
    }

    /**
     * @return array<string, float|int>
     */
    private function aggregate(EvidenceDefinition $definition, int $digitalAssetId, string $start, string $end): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $select = array_map(
            static fn (string $field): string => 'SUM('.$grammar->wrap($field).') as '.$grammar->wrap($field),
            $definition->metricFields,
        );
        $select[] = 'COUNT(*) as _rows';

        $row = DB::table($definition->physicalTable)
            ->where('digital_asset_id', $digitalAssetId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw(implode(', ', $select))
            ->first();

        $out = ['_rows' => 0];
        foreach ($definition->metricFields as $field) {
            $out[$field] = $row !== null ? (float) ($row->{$field} ?? 0) : 0.0;
        }
        $out['_rows'] = $row !== null ? (int) ($row->_rows ?? 0) : 0;

        return $out;
    }

    private function resolveGrain(EvidenceDefinition $definition, int $digitalAssetId, int $resourceId): string
    {
        $value = DB::table($definition->physicalTable)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $resourceId)
            ->orderByDesc('reporting_date')
            ->value($definition->grainColumn);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = DB::table($definition->physicalTable)
            ->where('digital_asset_id', $digitalAssetId)
            ->orderByDesc('reporting_date')
            ->value($definition->grainColumn);

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * @return array{value: ?float, state: string}
     */
    private function relativeChange(EvidenceDefinition $definition, float $current, float $previous): array
    {
        $result = match ($definition->provider) {
            'GA4' => $this->ga4Formulas->periodRelativeChange($current, $previous),
            'GOOGLE_ADS' => $this->googleAdsFormulas->periodRelativeChange($current, $previous),
            'META_ADS' => $this->metaAdsFormulas->periodRelativeChange($current, $previous),
            default => $this->gscFormulas->periodRelativeChange($current, $previous),
        };

        return [
            'value' => $result->isValue() ? $result->value : null,
            'state' => $result->state,
        ];
    }

    private function resolveCurrency(EvidenceDefinition $definition, int $digitalAssetId, int $resourceId): ?string
    {
        if ($definition->provider !== 'GOOGLE_ADS' && $definition->provider !== 'META_ADS') {
            return null;
        }

        $value = DB::table($definition->physicalTable)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $resourceId)
            ->orderByDesc('reporting_date')
            ->value('currency');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
