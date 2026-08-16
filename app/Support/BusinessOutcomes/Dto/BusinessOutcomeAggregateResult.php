<?php

namespace App\Support\BusinessOutcomes\Dto;

use App\Enums\BusinessOutcomeAggregateStatus;
use App\Enums\BusinessOutcomeCompleteness;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeUnit;

/**
 * Typed aggregate Business Outcome result (Prompt 57).
 */
final class BusinessOutcomeAggregateResult
{
    /**
     * @param  list<array{start: string, end: string}>  $coveredPeriods
     * @param  list<array{start: string, end: string}>  $gaps
     * @param  list<int>  $observationRevisionIds
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly ?int $definitionId,
        public readonly ?BusinessOutcomeKind $kind,
        public readonly string $requestedStart,
        public readonly string $requestedEnd,
        public readonly array $coveredPeriods,
        public readonly ?string $value,
        public readonly BusinessOutcomeUnit $unit,
        public readonly ?string $currencyCode,
        public readonly BusinessOutcomeAggregateStatus $status,
        public readonly ?BusinessOutcomeCompleteness $worstCompleteness,
        public readonly array $gaps,
        public readonly array $observationRevisionIds,
        public readonly array $limitations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'kind' => $this->kind?->value,
            'requested_period' => [
                'start' => $this->requestedStart,
                'end' => $this->requestedEnd,
            ],
            'covered_periods' => $this->coveredPeriods,
            'value' => $this->value,
            'unit' => $this->unit->value,
            'currency_code' => $this->currencyCode,
            'status' => $this->status->value,
            'worst_completeness' => $this->worstCompleteness?->value,
            'gaps' => $this->gaps,
            'observation_revision_ids' => $this->observationRevisionIds,
            'limitations' => $this->limitations,
            'missing_as_zero' => false,
            'prorated' => false,
            'channel_attribution' => null,
        ];
    }
}
