<?php

namespace App\Services\DataPool\Freshness;

use App\Enums\DataPool\DatasetCollectionMode;
use App\Enums\DataPool\FreshnessState;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\Support\CollectionClock;
use App\Services\DataPool\Freshness\Support\FreshnessEvaluation;
use App\Services\DataPool\Freshness\Support\WatermarkSnapshot;
use Carbon\CarbonImmutable;

/**
 * Provider-neutral freshness evaluator. Fresh ≠ last HTTP success.
 */
final class DatasetFreshnessEvaluator
{
    public function __construct(
        private readonly DatasetWatermarkCalculator $watermarks = new DatasetWatermarkCalculator,
        private readonly CollectionClock $clock = new CollectionClock,
    ) {}

    /**
     * @param  array<string, mixed>  $policy
     * @param  array{
     *   authorization_ready?: bool,
     *   integrity_blocked?: bool,
     *   provider_history_limited?: bool,
     *   provider_limitation_accepted?: bool,
     *   reporting_timezone?: ?string
     * }  $context
     */
    public function evaluate(
        array $policy,
        ?DatasetMaterialization $materialization,
        array $context = [],
    ): FreshnessEvaluation {
        if (($context['authorization_ready'] ?? true) === false) {
            return new FreshnessEvaluation(
                state: FreshnessState::ActionRequired,
                collectionDue: false,
                reprocessDue: false,
                reason: 'authorization_or_binding_not_ready',
                details: ['operator_action' => 'restore_authorization_or_binding'],
            );
        }

        if (($context['integrity_blocked'] ?? false) === true) {
            return new FreshnessEvaluation(
                state: FreshnessState::IntegrityBlocked,
                collectionDue: false,
                reprocessDue: false,
                reason: 'prompt26_blocking_integrity_failure',
                details: ['operator_action' => 'resolve_integrity_before_trusted_fresh'],
            );
        }

        if (($policy['incremental_applicable'] ?? true) === false) {
            return new FreshnessEvaluation(
                state: FreshnessState::Unknown,
                collectionDue: false,
                reprocessDue: false,
                reason: (string) ($policy['non_applicable_reason'] ?? 'incremental_not_applicable'),
            );
        }

        $mode = DatasetCollectionMode::tryFrom((string) ($policy['collection_mode'] ?? ''))
            ?? DatasetCollectionMode::HistoricalIncremental;

        $watermark = $this->watermarks->calculate(
            $materialization,
            $policy,
            is_string($context['reporting_timezone'] ?? null) ? (string) $context['reporting_timezone'] : null,
        );

        if ($mode === DatasetCollectionMode::CurrentSnapshot) {
            return $this->evaluateSnapshot($policy, $materialization, $watermark, $context);
        }

        return $this->evaluateHistorical($policy, $materialization, $watermark, $context);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $context
     */
    private function evaluateSnapshot(
        array $policy,
        ?DatasetMaterialization $materialization,
        WatermarkSnapshot $watermark,
        array $context,
    ): FreshnessEvaluation {
        if ($materialization === null || $materialization->last_collected_at === null
            || $materialization->status === MaterializationStatus::NotCollected) {
            return new FreshnessEvaluation(
                state: FreshnessState::Due,
                collectionDue: true,
                reprocessDue: false,
                reason: 'snapshot_never_collected',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        $slaHours = (int) ($policy['freshness_sla_hours'] ?? 168);
        $last = CarbonImmutable::parse($materialization->last_collected_at);
        $now = $this->clock->now('UTC');
        $ageHours = $last->diffInHours($now);

        if ($ageHours > $slaHours) {
            return new FreshnessEvaluation(
                state: FreshnessState::Stale,
                collectionDue: true,
                reprocessDue: false,
                reason: 'snapshot_sla_exceeded',
                details: [
                    'age_hours' => $ageHours,
                    'sla_hours' => $slaHours,
                    'watermark' => $watermark->toArray(),
                ],
            );
        }

        if ($ageHours >= max(1, (int) floor($slaHours * 0.75))) {
            return new FreshnessEvaluation(
                state: FreshnessState::Due,
                collectionDue: true,
                reprocessDue: false,
                reason: 'snapshot_refresh_due_within_sla',
                details: [
                    'age_hours' => $ageHours,
                    'sla_hours' => $slaHours,
                    'watermark' => $watermark->toArray(),
                ],
            );
        }

        return new FreshnessEvaluation(
            state: FreshnessState::Fresh,
            collectionDue: false,
            reprocessDue: false,
            reason: 'snapshot_within_sla',
            details: [
                'age_hours' => $ageHours,
                'sla_hours' => $slaHours,
                'watermark' => $watermark->toArray(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $context
     */
    private function evaluateHistorical(
        array $policy,
        ?DatasetMaterialization $materialization,
        WatermarkSnapshot $watermark,
        array $context,
    ): FreshnessEvaluation {
        if (($context['provider_history_limited'] ?? false) === true
            && ($context['provider_limitation_accepted'] ?? false) === true
            && $watermark->verifiedContiguousWatermark !== null
            && $watermark->verifiedContiguousWatermark >= (string) $watermark->currentCollectableEnd) {
            return new FreshnessEvaluation(
                state: FreshnessState::FreshWithLimitation,
                collectionDue: false,
                reprocessDue: false,
                reason: 'coverage_meets_provider_obtainable_boundary',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        if (($context['provider_history_limited'] ?? false) === true
            && ($context['provider_limitation_accepted'] ?? false) !== true) {
            return new FreshnessEvaluation(
                state: FreshnessState::ProviderLimited,
                collectionDue: false,
                reprocessDue: false,
                reason: 'provider_history_limitation_unaccepted',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        if ($watermark->internalGaps !== []) {
            return new FreshnessEvaluation(
                state: FreshnessState::Partial,
                collectionDue: true,
                reprocessDue: false,
                reason: 'internal_coverage_gap',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        $collectableEnd = $watermark->currentCollectableEnd;
        $verified = $watermark->verifiedContiguousWatermark;

        if ($collectableEnd === null) {
            return new FreshnessEvaluation(
                state: FreshnessState::Unknown,
                collectionDue: false,
                reprocessDue: false,
                reason: 'collectable_end_unresolved',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        if ($verified === null) {
            if ($materialization === null || $materialization->status === MaterializationStatus::NotCollected) {
                return new FreshnessEvaluation(
                    state: FreshnessState::Due,
                    collectionDue: true,
                    reprocessDue: false,
                    reason: 'never_collected_or_unproven_coverage',
                    details: ['watermark' => $watermark->toArray()],
                );
            }

            return new FreshnessEvaluation(
                state: FreshnessState::Unknown,
                collectionDue: true,
                reprocessDue: false,
                reason: 'coverage_continuity_unproven',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        $reprocessDue = $this->reprocessDue($policy, $verified, $collectableEnd, $materialization);
        $newDataDue = $verified < $collectableEnd;

        if (! $newDataDue && ! $reprocessDue) {
            return new FreshnessEvaluation(
                state: FreshnessState::Fresh,
                collectionDue: false,
                reprocessDue: false,
                reason: 'verified_coverage_meets_collectable_end',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        $slaHours = (int) ($policy['freshness_sla_hours'] ?? 48);
        $stale = false;
        if ($newDataDue) {
            $lagDays = CarbonImmutable::parse($verified)->diffInDays(CarbonImmutable::parse($collectableEnd));
            // SLA is hours since last successful collection when collectable coverage is behind.
            $lastAt = $materialization?->last_collected_at;
            if ($lastAt !== null) {
                $ageHours = CarbonImmutable::parse($lastAt)->diffInHours($this->clock->now('UTC'));
                $stale = $ageHours > $slaHours || $lagDays >= max(2, (int) ceil($slaHours / 24));
            } else {
                $stale = $lagDays >= 1;
            }
        }

        if ($stale) {
            return new FreshnessEvaluation(
                state: FreshnessState::Stale,
                collectionDue: true,
                reprocessDue: $reprocessDue,
                reason: 'freshness_sla_exceeded_or_collectable_coverage_lag',
                details: ['watermark' => $watermark->toArray()],
            );
        }

        return new FreshnessEvaluation(
            state: FreshnessState::Due,
            collectionDue: true,
            reprocessDue: $reprocessDue,
            reason: $newDataDue ? 'new_collectable_periods_exist' : 'late_data_reprocess_due',
            details: ['watermark' => $watermark->toArray()],
        );
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function reprocessDue(
        array $policy,
        string $verified,
        string $collectableEnd,
        ?DatasetMaterialization $materialization,
    ): bool {
        $strategy = (string) ($policy['late_data_reprocessing']['strategy'] ?? 'none');
        $window = $policy['late_data_reprocessing']['window_days'] ?? null;
        if ($strategy !== 'fixed_recent_reporting_window' || ! is_int($window) || $window < 1) {
            return false;
        }

        if ($verified < $collectableEnd) {
            // New coverage run will include overlapping reprocess window.
            return true;
        }

        $meta = is_array($materialization?->freshness_metadata) ? $materialization->freshness_metadata : [];
        $lastReprocessThrough = is_string($meta['last_reprocess_through'] ?? null)
            ? (string) $meta['last_reprocess_through']
            : null;

        return $lastReprocessThrough === null || $lastReprocessThrough < $collectableEnd;
    }
}
