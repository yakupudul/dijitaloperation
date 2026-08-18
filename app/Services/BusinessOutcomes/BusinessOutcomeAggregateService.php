<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeAggregateStatus;
use App\Enums\BusinessOutcomeCompleteness;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeObservationStatus;
use App\Enums\BusinessOutcomeUnit;
use App\Models\BusinessOutcomeDefinition;
use App\Models\BusinessOutcomeObservation;
use App\Support\BusinessOutcomes\Dto\BusinessOutcomeAggregateResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic Business Outcome aggregation — no AI, no proration, no FX.
 */
final class BusinessOutcomeAggregateService
{
    public function aggregate(
        BusinessOutcomeDefinition $definition,
        string $requestedStart,
        string $requestedEnd,
    ): BusinessOutcomeAggregateResult {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $requestedStart)->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $requestedEnd)->startOfDay();
        if ($end->lt($start)) {
            throw new \InvalidArgumentException('END_BEFORE_START');
        }

        /** @var Collection<int, BusinessOutcomeObservation> $rows */
        $rows = BusinessOutcomeObservation::query()
            ->with('currentRevision')
            ->where('definition_id', $definition->id)
            ->where('status', BusinessOutcomeObservationStatus::Active)
            ->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())
            ->orderBy('period_start')
            ->get();

        if ($rows->isEmpty()) {
            return new BusinessOutcomeAggregateResult(
                definitionId: (int) $definition->id,
                kind: $definition->kind,
                requestedStart: $start->toDateString(),
                requestedEnd: $end->toDateString(),
                coveredPeriods: [],
                value: null,
                unit: $definition->unit,
                currencyCode: $definition->currency_code,
                status: BusinessOutcomeAggregateStatus::NoData,
                worstCompleteness: null,
                gaps: [['start' => $start->toDateString(), 'end' => $end->toDateString()]],
                observationRevisionIds: [],
                limitations: ['no_data'],
            );
        }

        // Overlap integrity among returned rows
        $sorted = $rows->sortBy(fn ($o) => $o->period_start->toDateString())->values();
        for ($i = 1; $i < $sorted->count(); $i++) {
            $prev = $sorted[$i - 1];
            $curr = $sorted[$i];
            if ($curr->period_start->lte($prev->period_end)) {
                return new BusinessOutcomeAggregateResult(
                    definitionId: (int) $definition->id,
                    kind: $definition->kind,
                    requestedStart: $start->toDateString(),
                    requestedEnd: $end->toDateString(),
                    coveredPeriods: [],
                    value: null,
                    unit: $definition->unit,
                    currencyCode: $definition->currency_code,
                    status: BusinessOutcomeAggregateStatus::OverlapConflict,
                    worstCompleteness: null,
                    gaps: [],
                    observationRevisionIds: [],
                    limitations: ['overlap_conflict'],
                );
            }
        }

        // Unsupported grain: observation extends outside requested range (no proration)
        foreach ($sorted as $observation) {
            if ($observation->period_start->lt($start) || $observation->period_end->gt($end)) {
                // Observation partially outside requested window — cannot claim exact subset.
                if ($observation->period_start->lt($start) || $observation->period_end->gt($end)) {
                    $fullyInside = $observation->period_start->gte($start) && $observation->period_end->lte($end);
                    if (! $fullyInside) {
                        return new BusinessOutcomeAggregateResult(
                            definitionId: (int) $definition->id,
                            kind: $definition->kind,
                            requestedStart: $start->toDateString(),
                            requestedEnd: $end->toDateString(),
                            coveredPeriods: [],
                            value: null,
                            unit: $definition->unit,
                            currencyCode: $definition->currency_code,
                            status: BusinessOutcomeAggregateStatus::UnsupportedGrain,
                            worstCompleteness: null,
                            gaps: [],
                            observationRevisionIds: [],
                            limitations: ['no_proration', 'unsupported_grain'],
                        );
                    }
                }
            }
        }

        $usable = $sorted->filter(function (BusinessOutcomeObservation $observation) use ($start, $end): bool {
            return $observation->period_start->gte($start) && $observation->period_end->lte($end);
        })->values();

        if ($usable->isEmpty()) {
            return new BusinessOutcomeAggregateResult(
                definitionId: (int) $definition->id,
                kind: $definition->kind,
                requestedStart: $start->toDateString(),
                requestedEnd: $end->toDateString(),
                coveredPeriods: [],
                value: null,
                unit: $definition->unit,
                currencyCode: $definition->currency_code,
                status: BusinessOutcomeAggregateStatus::UnsupportedGrain,
                worstCompleteness: null,
                gaps: [],
                observationRevisionIds: [],
                limitations: ['no_proration'],
            );
        }

        $currencies = [];
        $sum = '0';
        $revisionIds = [];
        $covered = [];
        $worst = BusinessOutcomeCompleteness::Complete;

        foreach ($usable as $observation) {
            $rev = $observation->currentRevision;
            if ($rev === null) {
                continue;
            }
            $revisionIds[] = (int) $rev->id;
            $covered[] = [
                'start' => $observation->period_start->toDateString(),
                'end' => $observation->period_end->toDateString(),
            ];

            if ($definition->unit === BusinessOutcomeUnit::Money) {
                $currencies[] = strtoupper((string) $rev->currency_code);
                $sum = bcadd($sum, (string) $rev->value_numeric, 4);
            } else {
                $sum = bcadd($sum, (string) ($rev->value_count ?? $rev->value_numeric), 0);
            }

            $worst = $this->worseCompleteness($worst, $rev->completeness);
        }

        $currencies = array_values(array_unique(array_filter($currencies)));
        if ($definition->unit === BusinessOutcomeUnit::Money && count($currencies) > 1) {
            return new BusinessOutcomeAggregateResult(
                definitionId: (int) $definition->id,
                kind: $definition->kind,
                requestedStart: $start->toDateString(),
                requestedEnd: $end->toDateString(),
                coveredPeriods: $covered,
                value: null,
                unit: $definition->unit,
                currencyCode: null,
                status: BusinessOutcomeAggregateStatus::IncompatibleCurrency,
                worstCompleteness: $worst,
                gaps: [],
                observationRevisionIds: $revisionIds,
                limitations: ['mixed_currency', 'no_silent_fx'],
            );
        }

        $gaps = $this->computeGaps($start, $end, $covered);
        $status = $this->resolveStatus($gaps, $worst, $start, $end, $covered);

        return new BusinessOutcomeAggregateResult(
            definitionId: (int) $definition->id,
            kind: $definition->kind,
            requestedStart: $start->toDateString(),
            requestedEnd: $end->toDateString(),
            coveredPeriods: $covered,
            value: $definition->unit === BusinessOutcomeUnit::Money ? $sum : (string) (int) $sum,
            unit: $definition->unit,
            currencyCode: $definition->unit === BusinessOutcomeUnit::Money
                ? ($currencies[0] ?? $definition->currency_code)
                : null,
            status: $status,
            worstCompleteness: $worst,
            gaps: $gaps,
            observationRevisionIds: $revisionIds,
            limitations: array_values(array_filter([
                $status === BusinessOutcomeAggregateStatus::Partial ? 'partial_coverage' : null,
                $status === BusinessOutcomeAggregateStatus::UnknownCompleteness ? 'unknown_completeness' : null,
            ])),
        );
    }

    public function aggregateByKind(
        int $brandId,
        BusinessOutcomeKind $kind,
        string $requestedStart,
        string $requestedEnd,
    ): BusinessOutcomeAggregateResult {
        $definition = BusinessOutcomeDefinition::query()
            ->where('brand_id', $brandId)
            ->where('kind', $kind->value)
            ->where('status', 'active')
            ->first();

        if ($definition === null) {
            return new BusinessOutcomeAggregateResult(
                definitionId: null,
                kind: $kind,
                requestedStart: $requestedStart,
                requestedEnd: $requestedEnd,
                coveredPeriods: [],
                value: null,
                unit: $kind->unit(),
                currencyCode: null,
                status: BusinessOutcomeAggregateStatus::NoData,
                worstCompleteness: null,
                gaps: [['start' => $requestedStart, 'end' => $requestedEnd]],
                observationRevisionIds: [],
                limitations: ['no_definition'],
            );
        }

        return $this->aggregate($definition, $requestedStart, $requestedEnd);
    }

    private function worseCompleteness(
        BusinessOutcomeCompleteness $current,
        BusinessOutcomeCompleteness $next,
    ): BusinessOutcomeCompleteness {
        $rank = [
            BusinessOutcomeCompleteness::Complete->value => 0,
            BusinessOutcomeCompleteness::Partial->value => 1,
            BusinessOutcomeCompleteness::Unknown->value => 2,
        ];

        return ($rank[$next->value] ?? 2) > ($rank[$current->value] ?? 0) ? $next : $current;
    }

    /**
     * @param  list<array{start: string, end: string}>  $covered
     * @return list<array{start: string, end: string}>
     */
    private function computeGaps(CarbonImmutable $start, CarbonImmutable $end, array $covered): array
    {
        $gaps = [];
        $cursor = $start;
        foreach ($covered as $period) {
            $pStart = CarbonImmutable::createFromFormat('Y-m-d', $period['start'])->startOfDay();
            $pEnd = CarbonImmutable::createFromFormat('Y-m-d', $period['end'])->startOfDay();
            if ($cursor->lt($pStart)) {
                $gaps[] = [
                    'start' => $cursor->toDateString(),
                    'end' => $pStart->subDay()->toDateString(),
                ];
            }
            $next = $pEnd->addDay();
            if ($next->gt($cursor)) {
                $cursor = $next;
            }
        }
        if ($cursor->lte($end)) {
            $gaps[] = [
                'start' => $cursor->toDateString(),
                'end' => $end->toDateString(),
            ];
        }

        return $gaps;
    }

    /**
     * @param  list<array{start: string, end: string}>  $gaps
     * @param  list<array{start: string, end: string}>  $covered
     */
    private function resolveStatus(
        array $gaps,
        BusinessOutcomeCompleteness $worst,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $covered,
    ): BusinessOutcomeAggregateStatus {
        if ($worst === BusinessOutcomeCompleteness::Unknown) {
            return BusinessOutcomeAggregateStatus::UnknownCompleteness;
        }
        if ($worst === BusinessOutcomeCompleteness::Partial || $gaps !== []) {
            return BusinessOutcomeAggregateStatus::Partial;
        }

        // Full contiguous coverage by COMPLETE observations
        $coveredDays = 0;
        foreach ($covered as $period) {
            $pStart = CarbonImmutable::createFromFormat('Y-m-d', $period['start']);
            $pEnd = CarbonImmutable::createFromFormat('Y-m-d', $period['end']);
            $coveredDays += $pStart->diffInDays($pEnd) + 1;
        }
        $requestedDays = $start->diffInDays($end) + 1;
        if ($coveredDays === $requestedDays && $gaps === []) {
            return BusinessOutcomeAggregateStatus::Complete;
        }

        return BusinessOutcomeAggregateStatus::Partial;
    }
}
