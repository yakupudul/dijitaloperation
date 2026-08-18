<?php

namespace App\Services\DataPool\Freshness;

use App\Enums\Collection\IncrementalWorkReason;
use App\Enums\Collection\PlanDisposition;
use App\Enums\DataPool\DatasetCollectionMode;
use App\Enums\DataPool\FreshnessState;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Freshness\Support\FreshnessEvaluation;
use App\Services\DataPool\Freshness\Support\IncrementalDatasetDecision;
use Carbon\CarbonImmutable;

/**
 * Provider-neutral incremental / catch-up / reprocess / gap planner.
 * Does not build GAQL, GA4 bodies, GSC payloads, or Meta Insights requests.
 */
final class IncrementalCoveragePlanner
{
    public function __construct(
        private readonly DataFreshnessPolicyLoader $policies,
        private readonly DatasetFreshnessEvaluator $evaluator = new DatasetFreshnessEvaluator,
        private readonly DatasetWatermarkCalculator $watermarks = new DatasetWatermarkCalculator,
    ) {}

    /**
     * @param  array{
     *   authorization_ready?: bool,
     *   integrity_blocked?: bool,
     *   provider_history_limited?: bool,
     *   provider_limitation_accepted?: bool,
     *   reporting_timezone?: ?string
     * }  $context
     */
    public function planDataset(
        string $datasetId,
        ?DatasetMaterialization $materialization,
        array $context = [],
    ): IncrementalDatasetDecision {
        $policy = $this->policies->policy($datasetId);
        if ($policy === null) {
            return IncrementalDatasetDecision::blocked(
                datasetId: $datasetId,
                state: FreshnessState::Unknown,
                disposition: PlanDisposition::Unsupported,
                policyVersion: $this->policies->version(),
                reason: 'missing_freshness_policy',
            );
        }

        $policyVersion = (int) ($policy['policy_version'] ?? $this->policies->version());
        $evaluation = $this->evaluator->evaluate($policy, $materialization, $context);

        if ($evaluation->state === FreshnessState::ActionRequired) {
            return IncrementalDatasetDecision::blocked(
                datasetId: $datasetId,
                state: $evaluation->state,
                disposition: PlanDisposition::ActionRequired,
                policyVersion: $policyVersion,
                reason: $evaluation->reason,
                details: $evaluation->toArray(),
            );
        }

        if ($evaluation->state === FreshnessState::IntegrityBlocked) {
            return IncrementalDatasetDecision::blocked(
                datasetId: $datasetId,
                state: $evaluation->state,
                disposition: PlanDisposition::IntegrityBlocked,
                policyVersion: $policyVersion,
                reason: $evaluation->reason,
                details: $evaluation->toArray(),
            );
        }

        if ($evaluation->state === FreshnessState::ProviderLimited) {
            return IncrementalDatasetDecision::blocked(
                datasetId: $datasetId,
                state: $evaluation->state,
                disposition: PlanDisposition::ProviderLimited,
                policyVersion: $policyVersion,
                reason: $evaluation->reason,
                details: $evaluation->toArray(),
            );
        }

        if (($policy['incremental_applicable'] ?? true) === false) {
            return IncrementalDatasetDecision::blocked(
                datasetId: $datasetId,
                state: FreshnessState::Unknown,
                disposition: PlanDisposition::NotEligible,
                policyVersion: $policyVersion,
                reason: (string) ($policy['non_applicable_reason'] ?? 'not_applicable'),
                details: $evaluation->toArray(),
            );
        }

        $mode = DatasetCollectionMode::tryFrom((string) ($policy['collection_mode'] ?? ''))
            ?? DatasetCollectionMode::HistoricalIncremental;

        if ($mode === DatasetCollectionMode::CurrentSnapshot) {
            return $this->planSnapshot($datasetId, $policy, $policyVersion, $evaluation);
        }

        return $this->planHistorical($datasetId, $policy, $policyVersion, $materialization, $evaluation, $context);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function planSnapshot(
        string $datasetId,
        array $policy,
        int $policyVersion,
        FreshnessEvaluation $evaluation,
    ): IncrementalDatasetDecision {
        if (! $evaluation->collectionDue) {
            return IncrementalDatasetDecision::alreadyCurrent(
                datasetId: $datasetId,
                state: $evaluation->state,
                policyVersion: $policyVersion,
                reason: $evaluation->reason,
                details: $evaluation->toArray(),
            );
        }

        return new IncrementalDatasetDecision(
            datasetId: $datasetId,
            freshnessState: $evaluation->state,
            planDisposition: PlanDisposition::Eligible,
            executable: true,
            dateRange: null,
            requestedIntervals: [[
                'start' => null,
                'end' => null,
                'reasons' => [IncrementalWorkReason::SnapshotRefresh->value],
            ]],
            reasons: [IncrementalWorkReason::SnapshotRefresh->value],
            policyVersion: $policyVersion,
            reasonSummary: 'SNAPSHOT_REFRESH',
            details: $evaluation->toArray(),
        );
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $context
     */
    private function planHistorical(
        string $datasetId,
        array $policy,
        int $policyVersion,
        ?DatasetMaterialization $materialization,
        FreshnessEvaluation $evaluation,
        array $context,
    ): IncrementalDatasetDecision {
        $watermark = $this->watermarks->calculate(
            $materialization,
            $policy,
            is_string($context['reporting_timezone'] ?? null) ? (string) $context['reporting_timezone'] : null,
        );

        $collectableEnd = $watermark->currentCollectableEnd;
        if ($collectableEnd === null) {
            return IncrementalDatasetDecision::blocked(
                datasetId: $datasetId,
                state: FreshnessState::Unknown,
                disposition: PlanDisposition::NotEligible,
                policyVersion: $policyVersion,
                reason: 'no_collectable_end',
                details: $evaluation->toArray(),
            );
        }

        /** @var array<string, array{start: string, end: string, reasons: list<string>}> $intervalMap */
        $intervalMap = [];

        // Gap recovery — never skip past unresolved internal gaps.
        foreach ($watermark->internalGaps as $gap) {
            $this->mergeInterval($intervalMap, $gap['start'], $gap['end'], IncrementalWorkReason::GapRecovery);
        }

        $verified = $watermark->verifiedContiguousWatermark;
        if ($verified !== null && $verified < $collectableEnd) {
            $newStart = CarbonImmutable::parse($verified)->addDay()->toDateString();
            if ($newStart <= $collectableEnd) {
                $reason = $newStart < $collectableEnd
                    ? IncrementalWorkReason::CatchUp
                    : IncrementalWorkReason::NewCoverage;
                // Multi-day missing collectable coverage is catch-up even when contiguous.
                $spanDays = CarbonImmutable::parse($newStart)->diffInDays(CarbonImmutable::parse($collectableEnd)) + 1;
                if ($spanDays > 1) {
                    $reason = IncrementalWorkReason::CatchUp;
                }
                $this->mergeInterval($intervalMap, $newStart, $collectableEnd, $reason);
            }
        } elseif ($verified === null && $watermark->continuityProven === false) {
            // Unproven continuity: do not invent full history; surface as non-executable unknown
            // unless evaluation already says due for never collected (initial backfill owns baseline).
            if ($materialization === null) {
                return IncrementalDatasetDecision::blocked(
                    datasetId: $datasetId,
                    state: FreshnessState::Due,
                    disposition: PlanDisposition::NotEligible,
                    policyVersion: $policyVersion,
                    reason: 'initial_backfill_required_before_incremental',
                    details: array_merge($evaluation->toArray(), ['watermark' => $watermark->toArray()]),
                );
            }
        }

        // Late-data reprocessing window (may overlap existing coverage).
        $reprocess = $policy['late_data_reprocessing'] ?? [];
        if ($evaluation->reprocessDue
            && ($reprocess['strategy'] ?? '') === 'fixed_recent_reporting_window'
            && is_int($reprocess['window_days'] ?? null)
            && (int) $reprocess['window_days'] > 0
            && $verified !== null) {
            $window = (int) $reprocess['window_days'];
            $reprocessEnd = min($collectableEnd, $verified > $collectableEnd ? $collectableEnd : max($verified, $collectableEnd));
            // Reprocess through the lesser of verified and collectable end for already-covered days,
            // and through collectable end when new coverage is also planned.
            $reprocessEnd = $collectableEnd;
            $reprocessStart = CarbonImmutable::parse($reprocessEnd)->subDays($window - 1)->toDateString();
            if ($verified !== null) {
                // Do not reprocess before known coverage begins if intervals exist.
                $boundsStart = $watermark->coverageIntervals[0]['start'] ?? null;
                if (is_string($boundsStart) && $reprocessStart < $boundsStart) {
                    $reprocessStart = $boundsStart;
                }
            }
            if ($reprocessStart <= $reprocessEnd) {
                $this->mergeInterval($intervalMap, $reprocessStart, $reprocessEnd, IncrementalWorkReason::LateDataReprocess);
            }
        }

        if ($intervalMap === []) {
            if ($evaluation->state->trustedFresh() || $evaluation->state === FreshnessState::Fresh) {
                return IncrementalDatasetDecision::alreadyCurrent(
                    datasetId: $datasetId,
                    state: $evaluation->state,
                    policyVersion: $policyVersion,
                    reason: $evaluation->reason,
                    details: array_merge($evaluation->toArray(), ['watermark' => $watermark->toArray()]),
                );
            }

            return IncrementalDatasetDecision::alreadyCurrent(
                datasetId: $datasetId,
                state: $evaluation->state,
                policyVersion: $policyVersion,
                reason: 'no_executable_incremental_intervals',
                details: array_merge($evaluation->toArray(), ['watermark' => $watermark->toArray()]),
            );
        }

        $intervals = array_values($intervalMap);
        usort($intervals, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        // Bound catch-up / incremental span.
        $maxSpan = $policy['max_bounded_incremental_span_days'] ?? null;
        $envelopeStart = $intervals[0]['start'];
        $envelopeEnd = $intervals[array_key_last($intervals)]['end'];
        if (is_int($maxSpan) && $maxSpan > 0) {
            $span = CarbonImmutable::parse($envelopeStart)->diffInDays(CarbonImmutable::parse($envelopeEnd)) + 1;
            if ($span > $maxSpan) {
                $envelopeStart = CarbonImmutable::parse($envelopeEnd)->subDays($maxSpan - 1)->toDateString();
                $intervals = array_values(array_filter(
                    $intervals,
                    static fn (array $i): bool => $i['end'] >= $envelopeStart,
                ));
                foreach ($intervals as &$interval) {
                    if ($interval['start'] < $envelopeStart) {
                        $interval['start'] = $envelopeStart;
                    }
                }
                unset($interval);
            }
        }

        $reasons = [];
        foreach ($intervals as $interval) {
            foreach ($interval['reasons'] as $reason) {
                $reasons[$reason] = true;
            }
        }
        $reasonList = array_keys($reasons);

        return new IncrementalDatasetDecision(
            datasetId: $datasetId,
            freshnessState: $evaluation->state,
            planDisposition: PlanDisposition::Eligible,
            executable: true,
            dateRange: [
                'start' => $envelopeStart,
                'end' => $envelopeEnd,
            ],
            requestedIntervals: $intervals,
            reasons: $reasonList,
            policyVersion: $policyVersion,
            reasonSummary: implode('+', $reasonList),
            details: array_merge($evaluation->toArray(), [
                'watermark' => $watermark->toArray(),
                'policy_version' => $policyVersion,
            ]),
        );
    }

    /**
     * @param  array<string, array{start: string, end: string, reasons: list<string>}>  $intervalMap
     */
    private function mergeInterval(array &$intervalMap, string $start, string $end, IncrementalWorkReason $reason): void
    {
        if ($start > $end) {
            return;
        }

        $key = $start.'|'.$end;
        if (! isset($intervalMap[$key])) {
            $intervalMap[$key] = [
                'start' => $start,
                'end' => $end,
                'reasons' => [$reason->value],
            ];

            return;
        }

        if (! in_array($reason->value, $intervalMap[$key]['reasons'], true)) {
            $intervalMap[$key]['reasons'][] = $reason->value;
        }
    }
}
