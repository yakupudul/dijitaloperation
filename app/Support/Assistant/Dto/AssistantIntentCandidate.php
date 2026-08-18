<?php

namespace App\Support\Assistant\Dto;

use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantIntentType;

/**
 * Model-proposed or structured candidate intent — never executes data access.
 */
final class AssistantIntentCandidate
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly AssistantIntentType $intentType,
        public readonly ?AssistantCapabilityId $capabilityId = null,
        public readonly ?string $metricId = null,
        public readonly ?string $periodToken = null,
        public readonly ?string $domainFilter = null,
        public readonly ?string $scopeReference = null,
        public readonly array $parameters = [],
        public readonly bool $requestsWrite = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intentType->value,
            'capability' => $this->capabilityId?->value,
            'metric_id' => $this->metricId,
            'period' => $this->periodToken,
            'domain_filter' => $this->domainFilter,
            'scope_reference' => $this->scopeReference,
            'parameters' => $this->parameters,
            'requests_write' => $this->requestsWrite,
        ];
    }
}
