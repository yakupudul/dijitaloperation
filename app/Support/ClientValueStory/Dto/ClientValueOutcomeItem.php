<?php

namespace App\Support\ClientValueStory\Dto;

use App\Enums\BusinessOutcomeAggregateStatus;
use App\Enums\BusinessOutcomeCompleteness;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeUnit;

final class ClientValueOutcomeItem
{
    /**
     * @param  list<array{start: string, end: string}>  $coveredPeriods
     * @param  list<array{start: string, end: string}>  $gaps
     * @param  list<int>  $observationRevisionIds
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly BusinessOutcomeKind $kind,
        public readonly ?int $definitionId,
        public readonly string $displayLabel,
        public readonly BusinessOutcomeUnit $unit,
        public readonly ?string $value,
        public readonly ?string $currencyCode,
        public readonly BusinessOutcomeAggregateStatus $status,
        public readonly ?BusinessOutcomeCompleteness $completeness,
        public readonly array $coveredPeriods,
        public readonly array $gaps,
        public readonly array $observationRevisionIds,
        public readonly array $limitations,
    ) {}

    public function displayValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'definition_id' => $this->definitionId,
            'display_label' => $this->displayLabel,
            'unit' => $this->unit->value,
            'value' => $this->value,
            'currency_code' => $this->currencyCode,
            'status' => $this->status->value,
            'completeness' => $this->completeness?->value,
            'covered_periods' => $this->coveredPeriods,
            'gaps' => $this->gaps,
            'observation_revision_ids' => $this->observationRevisionIds,
            'limitations' => $this->limitations,
            'section' => 'reported_outcome',
            'channel_attribution' => null,
            'provider_conversion_fallback' => false,
        ];
    }
}
