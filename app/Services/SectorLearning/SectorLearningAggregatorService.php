<?php

namespace App\Services\SectorLearning;

use App\Enums\SectorLearningAggregator;
use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningCohortBand;
use App\Enums\SectorLearningPrivacyReasonCode;
use App\Support\SectorLearning\Dto\InternalSectorContribution;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;

/**
 * Deterministic Sector aggregation + cell suppression (Prompt 53).
 *
 * No AI. No causality inference. Suppressed ≠ zero.
 */
final class SectorLearningAggregatorService
{
    public const string VERSION = SectorLearningPrivacyPolicy::AGGREGATION_METHOD_VERSION;

    /**
     * @param  list<InternalSectorContribution>  $boundedContributions
     * @return array{
     *     ok: bool,
     *     aggregate_result: array<string, mixed>,
     *     reasons: list<string>,
     *     cohort_band: SectorLearningCohortBand,
     *     summary_text: string,
     *     limitations: list<string>
     * }
     */
    public function aggregateActionOutcomeAssociation(
        string $sectorCode,
        array $boundedContributions,
        int $distinctBrands,
        int $distinctCustomers,
    ): array {
        $cells = [];
        /** @var array<string, array{brands: array<int, true>, customers: array<int, true>, weight: float}> $raw */
        $raw = [];

        foreach ($boundedContributions as $contribution) {
            $key = $contribution->projection->actionKind.'|'.$contribution->projection->outcomeClarity;
            if (! isset($raw[$key])) {
                $raw[$key] = ['brands' => [], 'customers' => [], 'weight' => 0.0, 'action_kind' => $contribution->projection->actionKind, 'outcome_clarity' => $contribution->projection->outcomeClarity];
            }
            $raw[$key]['brands'][$contribution->brandId] = true;
            $raw[$key]['customers'][$contribution->customerId] = true;
            $raw[$key]['weight'] += $contribution->effectiveWeight;
        }

        $visible = [];
        $suppressed = [];
        $reasons = [];

        foreach ($raw as $key => $cell) {
            $brandN = count($cell['brands']);
            $customerN = count($cell['customers']);
            $payload = [
                'action_kind' => $cell['action_kind'],
                'outcome_clarity' => $cell['outcome_clarity'],
                'status' => 'visible',
                'effective_share_band' => null,
            ];

            if ($brandN < SectorLearningPrivacyPolicy::MIN_CATEGORICAL_CELL_BRANDS
                || $customerN < SectorLearningPrivacyPolicy::MIN_CATEGORICAL_CELL_CUSTOMERS) {
                $payload['status'] = 'SUPPRESSED_PRIVACY';
                $suppressed[$key] = $payload;
                $reasons[] = SectorLearningPrivacyReasonCode::SmallCategoricalCell->value;
            } else {
                // Do not expose exact cell contributor counts — band only at artifact level.
                $payload['effective_share_band'] = $this->shareBand($cell['weight'], $distinctBrands);
                $visible[$key] = $payload;
            }
        }

        // Complementary suppression only when visible cells expose reconstructable exact counts.
        $exposesExactCounts = false;
        foreach ($visible as $payload) {
            if (array_key_exists('count', $payload) && $payload['count'] !== null) {
                $exposesExactCounts = true;
            }
        }
        if ($exposesExactCounts && count($suppressed) === 1 && count($visible) === 1 && count($raw) === 2) {
            foreach ($visible as $key => $payload) {
                $payload['status'] = 'SUPPRESSED_PRIVACY';
                $suppressed[$key] = $payload;
                unset($visible[$key]);
            }
            $reasons[] = SectorLearningPrivacyReasonCode::ComplementaryDisclosureRisk->value;
        }

        if ($visible === []) {
            return [
                'ok' => false,
                'aggregate_result' => [
                    'kind' => SectorLearningArtifactKind::ActionOutcomeAssociation->value,
                    'aggregator' => SectorLearningAggregator::DirectionDistribution->value,
                    'cells' => array_values($suppressed),
                    'suppressed_cell_count' => count($suppressed),
                    'expose_min' => false,
                    'expose_max' => false,
                    'causality' => 'causality_not_established',
                ],
                'reasons' => array_values(array_unique(array_merge(
                    $reasons,
                    [SectorLearningPrivacyReasonCode::PostAggregationDisclosure->value]
                ))),
                'cohort_band' => SectorLearningCohortBand::fromDistinctBrandCount($distinctBrands),
                'summary_text' => 'No privacy-qualified cell distribution could be released for this cohort.',
                'limitations' => $this->defaultLimitations(),
            ];
        }

        $cells = array_values(array_merge($visible, array_map(
            static function (array $payload): array {
                // Suppressed cells must not look like zero counts.
                return [
                    'action_kind' => $payload['action_kind'],
                    'outcome_clarity' => $payload['outcome_clarity'],
                    'status' => 'SUPPRESSED_PRIVACY',
                    'effective_share_band' => null,
                    'count' => null,
                ];
            },
            $suppressed
        )));

        // Strip brand/customer identity from any accidental leakage
        foreach ($cells as &$cell) {
            unset($cell['brands'], $cell['customers'], $cell['weight']);
        }
        unset($cell);

        $cohortBand = SectorLearningCohortBand::fromDistinctBrandCount($distinctBrands);
        $summary = sprintf(
            'Across a privacy-qualified MoxDOP %s-sector cohort of %s independent Brands, experiences classified by action kind showed observational associations with later outcome clarity categories during the analyzed periods. This is an observational cohort pattern and does not establish causality.',
            $sectorCode,
            $cohortBand->value
        );

        return [
            'ok' => true,
            'aggregate_result' => [
                'schema' => 'sector_aggregate_action_outcome_v1',
                'kind' => SectorLearningArtifactKind::ActionOutcomeAssociation->value,
                'aggregator' => SectorLearningAggregator::DirectionDistribution->value,
                'cells' => $cells,
                'suppressed_cell_count' => count($suppressed),
                'expose_min' => false,
                'expose_max' => false,
                'causality' => 'causality_not_established',
                'source_label' => 'moxdop_cohort_observation',
                'industry_benchmark_claim' => false,
            ],
            'reasons' => array_values(array_unique($reasons)),
            'cohort_band' => $cohortBand,
            'summary_text' => $summary,
            'limitations' => $this->defaultLimitations(),
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultLimitations(): array
    {
        return [
            'MOXDOP_COHORT_OBSERVATION',
            'OBSERVATIONAL_ONLY',
            'CAUSALITY_NOT_ESTABLISHED',
            'NOT_EXTERNAL_INDUSTRY_BENCHMARK',
            'NON_RANDOM_CUSTOMER_SAMPLE',
            'PRIVACY_QUALIFIED',
            'COHORT_QUALIFIED',
        ];
    }

    private function shareBand(float $weight, int $distinctBrands): string
    {
        $share = $distinctBrands > 0 ? $weight / $distinctBrands : 0.0;
        if ($share < 0.25) {
            return 'low';
        }
        if ($share < 0.5) {
            return 'medium';
        }

        return 'high';
    }
}
