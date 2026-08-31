<?php

namespace App\Services\IntelligenceCore;

use RuntimeException;

final class IntelligenceMetricRegistry
{
    public function __construct(
        private readonly IntelligenceCoreRegistryLoader $loader,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $metrics = [];
        foreach ($this->loader->metrics() as $metric) {
            $metrics[(string) $metric['id']] = $metric;
        }

        return $metrics;
    }

    public function has(string $metricId): bool
    {
        return isset($this->all()[$metricId]);
    }

    /** @return array<string, mixed> */
    public function get(string $metricId): array
    {
        $metric = $this->all()[$metricId] ?? null;
        if (! is_array($metric)) {
            throw new RuntimeException("Unknown Intelligence Core metric [{$metricId}].");
        }

        return $metric;
    }
}
