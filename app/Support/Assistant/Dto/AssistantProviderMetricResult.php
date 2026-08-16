<?php

namespace App\Support\Assistant\Dto;

use App\Enums\AssistantCoverageState;
use App\Enums\AssistantFreshnessState;

/**
 * Typed provider metric result for deterministic fact lookup.
 */
final class AssistantProviderMetricResult
{
    /**
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly string $metricId,
        public readonly ?float $value,
        public readonly ?string $currency,
        public readonly ?string $unit,
        public readonly AssistantDateRange $requestedPeriod,
        public readonly ?AssistantDateRange $coveredPeriod,
        public readonly AssistantFreshnessState $freshness,
        public readonly AssistantCoverageState $coverage,
        public readonly int $digitalAssetId,
        public readonly string $provider,
        public readonly string $opaqueSourceRef,
        public readonly array $limitations = [],
        public readonly bool $unavailable = false,
        public readonly ?string $unavailableReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric_id' => $this->metricId,
            'value' => $this->value,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'requested_period' => $this->requestedPeriod->toArray(),
            'covered_period' => $this->coveredPeriod?->toArray(),
            'freshness' => $this->freshness->value,
            'coverage' => $this->coverage->value,
            'digital_asset_id' => $this->digitalAssetId,
            'provider' => $this->provider,
            'opaque_source_ref' => $this->opaqueSourceRef,
            'limitations' => $this->limitations,
            'unavailable' => $this->unavailable,
            'unavailable_reason' => $this->unavailableReason,
            'llm_calculated' => false,
        ];
    }
}
