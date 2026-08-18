<?php

namespace App\Services\Collection;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DatasetMaterialization;
use Carbon\CarbonImmutable;

/**
 * Compares DatasetMaterialization pool state to a planned initial coverage target.
 * Registry owns semantics; materialization owns existence.
 */
final class CoverageSatisfactionChecker
{
    /**
     * @param  array{
     *   kind: string,
     *   start: ?string,
     *   end: ?string,
     *   days: ?int
     * }  $target
     * @return array{
     *   disposition: string,
     *   reason: string,
     *   date_range: ?array{start: string, end: string},
     *   materialization_status: ?string,
     *   existing_coverage: ?array{start: ?string, end: ?string, partial: bool}
     * }
     */
    public function evaluate(
        ?DatasetMaterialization $materialization,
        array $target,
        bool $forceRefresh = false,
    ): array {
        if ($forceRefresh) {
            return $this->needsCollection($target, $materialization, 'force_refresh');
        }

        if ($materialization === null || $materialization->status === MaterializationStatus::NotCollected) {
            return $this->needsCollection($target, $materialization, 'never_collected');
        }

        $existing = [
            'start' => optional($materialization->coverage_start_date)?->toDateString(),
            'end' => optional($materialization->coverage_end_date)?->toDateString(),
            'partial' => (bool) $materialization->partial,
        ];

        if ($target['kind'] === 'snapshot') {
            if (in_array($materialization->status, [MaterializationStatus::Available, MaterializationStatus::Stale], true)
                && ! $materialization->partial
                && $materialization->last_collected_at !== null) {
                return [
                    'disposition' => 'already_satisfied',
                    'reason' => 'snapshot_materialization_present',
                    'date_range' => null,
                    'materialization_status' => $materialization->status->value,
                    'existing_coverage' => $existing,
                ];
            }

            return $this->needsCollection($target, $materialization, 'snapshot_missing_or_partial');
        }

        if ($target['kind'] !== 'historical' || $target['start'] === null || $target['end'] === null) {
            if ($materialization->last_collected_at !== null
                && $materialization->status === MaterializationStatus::Available
                && ! $materialization->partial) {
                return [
                    'disposition' => 'already_satisfied',
                    'reason' => 'non_ranged_materialization_present',
                    'date_range' => null,
                    'materialization_status' => $materialization->status->value,
                    'existing_coverage' => $existing,
                ];
            }

            return $this->needsCollection($target, $materialization, 'non_ranged_incomplete');
        }

        $covStart = $existing['start'];
        $covEnd = $existing['end'];

        if ($covStart === null || $covEnd === null || $materialization->partial) {
            return $this->partialContinuation($target, $materialization, $existing, 'partial_or_missing_bounds');
        }

        $targetStart = CarbonImmutable::parse($target['start']);
        $targetEnd = CarbonImmutable::parse($target['end']);
        $haveStart = CarbonImmutable::parse($covStart);
        $haveEnd = CarbonImmutable::parse($covEnd);

        if ($haveStart->lessThanOrEqualTo($targetStart) && $haveEnd->greaterThanOrEqualTo($targetEnd)
            && $materialization->status === MaterializationStatus::Available) {
            return [
                'disposition' => 'already_satisfied',
                'reason' => 'full_initial_coverage_present',
                'date_range' => null,
                'materialization_status' => $materialization->status->value,
                'existing_coverage' => $existing,
            ];
        }

        return $this->partialContinuation($target, $materialization, $existing, 'incomplete_coverage');
    }

    /**
     * @param  array{kind: string, start: ?string, end: ?string}  $target
     * @param  array{start: ?string, end: ?string, partial: bool}  $existing
     * @return array{
     *   disposition: string,
     *   reason: string,
     *   date_range: ?array{start: string, end: string},
     *   materialization_status: ?string,
     *   existing_coverage: ?array{start: ?string, end: ?string, partial: bool}
     * }
     */
    private function partialContinuation(
        array $target,
        ?DatasetMaterialization $materialization,
        array $existing,
        string $reason,
    ): array {
        if ($target['kind'] !== 'historical' || $target['start'] === null || $target['end'] === null) {
            return $this->needsCollection($target, $materialization, $reason);
        }

        $start = $target['start'];
        $end = $target['end'];

        // If we already have a prefix, continue from the day after coverage_end when safe.
        if ($existing['end'] !== null && ! ($existing['partial'] ?? false)) {
            $next = CarbonImmutable::parse($existing['end'])->addDay()->toDateString();
            if ($next <= $end && $next >= $start) {
                $start = $next;
            } elseif ($next > $end && $existing['start'] !== null && $existing['start'] <= $target['start']) {
                return [
                    'disposition' => 'already_satisfied',
                    'reason' => 'coverage_end_meets_target',
                    'date_range' => null,
                    'materialization_status' => $materialization?->status?->value,
                    'existing_coverage' => $existing,
                ];
            }
        }

        return [
            'disposition' => 'eligible',
            'reason' => $reason,
            'date_range' => ['start' => $start, 'end' => $end],
            'materialization_status' => $materialization?->status?->value,
            'existing_coverage' => $existing,
        ];
    }

    /**
     * @param  array{kind: string, start: ?string, end: ?string}  $target
     * @return array{
     *   disposition: string,
     *   reason: string,
     *   date_range: ?array{start: string, end: string},
     *   materialization_status: ?string,
     *   existing_coverage: ?array{start: ?string, end: ?string, partial: bool}
     * }
     */
    private function needsCollection(array $target, ?DatasetMaterialization $materialization, string $reason): array
    {
        $range = null;
        if ($target['kind'] === 'historical' && $target['start'] !== null && $target['end'] !== null) {
            $range = ['start' => $target['start'], 'end' => $target['end']];
        }

        return [
            'disposition' => 'eligible',
            'reason' => $reason,
            'date_range' => $range,
            'materialization_status' => $materialization?->status?->value,
            'existing_coverage' => $materialization === null ? null : [
                'start' => optional($materialization->coverage_start_date)?->toDateString(),
                'end' => optional($materialization->coverage_end_date)?->toDateString(),
                'partial' => (bool) $materialization->partial,
            ],
        ];
    }
}
