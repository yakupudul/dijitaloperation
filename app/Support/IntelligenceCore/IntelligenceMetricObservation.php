<?php

namespace App\Support\IntelligenceCore;

use App\Enums\IntelligenceCore\IntelligenceValueState;
use InvalidArgumentException;

final class IntelligenceMetricObservation
{
    /**
     * @param  array<string, int|string>  $dimensions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $metricId,
        public readonly IntelligenceValueState $state,
        public readonly int|float|null $value,
        public readonly string $unit,
        public readonly string $grain,
        public readonly array $dimensions,
        public readonly IntelligenceSourceReference $source,
        public readonly IntelligenceTimeContext $timeContext,
        public readonly ?string $currencyCode = null,
        public readonly array $metadata = [],
    ) {
        if ($this->state->carriesValue() && $this->value === null) {
            throw new InvalidArgumentException('Value-bearing intelligence states require a value.');
        }

        if (! $this->state->carriesValue() && $this->value !== null) {
            throw new InvalidArgumentException('Non-value intelligence states must not carry a value.');
        }

        if ($this->state === IntelligenceValueState::Zero && (float) $this->value !== 0.0) {
            throw new InvalidArgumentException('ZERO intelligence state must carry numeric zero.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric_id' => $this->metricId,
            'state' => $this->state->value,
            'value' => $this->value,
            'unit' => $this->unit,
            'currency_code' => $this->currencyCode,
            'grain' => $this->grain,
            'dimensions' => $this->dimensions,
            'source' => $this->source->toArray(),
            'time_context' => $this->timeContext->toArray(),
            'metadata' => $this->metadata,
        ];
    }
}
