<?php

namespace App\Services\IntelligenceCore;

use App\Enums\IntelligenceCore\IntelligenceValueState;
use App\Support\IntelligenceCore\IntelligenceMetricObservation;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use InvalidArgumentException;

final class IntelligenceMetricFactory
{
    public function __construct(
        private readonly IntelligenceMetricRegistry $metrics,
    ) {}

    /**
     * @param  array<string, int|string>  $dimensions
     * @param  array<string, mixed>  $metadata
     */
    public function make(
        string $metricId,
        IntelligenceValueState $state,
        int|float|null $value,
        string $grain,
        array $dimensions,
        IntelligenceSourceReference $source,
        IntelligenceTimeContext $timeContext,
        ?string $currencyCode = null,
        array $metadata = [],
    ): IntelligenceMetricObservation {
        $metric = $this->metrics->get($metricId);
        $metricSource = (string) ($metric['source'] ?? '');
        if ($metricSource !== $source->providerOrSource) {
            throw new InvalidArgumentException(
                "Metric [{$metricId}] belongs to [{$metricSource}], not [{$source->providerOrSource}].",
            );
        }

        $expectedClass = (string) ($metric['source_class'] ?? '');
        if ($expectedClass !== $source->sourceClass->value) {
            throw new InvalidArgumentException(
                "Metric [{$metricId}] requires source class [{$expectedClass}].",
            );
        }

        if (! in_array($grain, $metric['grains'] ?? [], true)) {
            throw new InvalidArgumentException("Metric [{$metricId}] does not support grain [{$grain}].");
        }

        $unit = (string) ($metric['unit'] ?? '');
        if ($unit === 'currency' && ($currencyCode === null || trim($currencyCode) === '')) {
            throw new InvalidArgumentException("Currency metric [{$metricId}] requires currency code.");
        }

        return new IntelligenceMetricObservation(
            metricId: $metricId,
            state: $state,
            value: $value,
            unit: $unit,
            grain: $grain,
            dimensions: $dimensions,
            source: $source,
            timeContext: $timeContext,
            currencyCode: $currencyCode,
            metadata: array_merge([
                'additivity' => $metric['additivity'] ?? 'UNSPECIFIED',
                'intelligence_core_registry_version' => 1,
            ], $metadata),
        );
    }
}
