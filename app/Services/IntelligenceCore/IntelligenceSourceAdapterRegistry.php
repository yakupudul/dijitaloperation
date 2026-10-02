<?php

namespace App\Services\IntelligenceCore;

use App\Contracts\IntelligenceCore\IntelligenceSourceAdapter;
use InvalidArgumentException;

final class IntelligenceSourceAdapterRegistry
{
    /** @var array<string, IntelligenceSourceAdapter> */
    private array $adapters = [];

    /** @param iterable<IntelligenceSourceAdapter> $adapters */
    public function __construct(
        iterable $adapters,
        private readonly IntelligenceCoreRegistryLoader $loader,
        private readonly IntelligenceCapabilityRegistry $capabilities,
        private readonly IntelligenceMetricRegistry $metrics,
    ) {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(IntelligenceSourceAdapter $adapter): void
    {
        $sourceId = $adapter->sourceId();
        if ($sourceId === '' || ! isset($this->sources()[$sourceId])) {
            throw new InvalidArgumentException("Unknown Intelligence Core source adapter [{$sourceId}].");
        }
        if (isset($this->adapters[$sourceId])) {
            throw new InvalidArgumentException("Duplicate Intelligence Core source adapter [{$sourceId}].");
        }

        $declaredCapabilities = $this->sources()[$sourceId]['capabilities'] ?? [];
        foreach ($adapter->capabilityIds() as $capabilityId) {
            if (! $this->capabilities->has($capabilityId) || ! in_array($capabilityId, $declaredCapabilities, true)) {
                throw new InvalidArgumentException(
                    "Adapter [{$sourceId}] exposes undeclared capability [{$capabilityId}].",
                );
            }
        }

        $profileIds = array_fill_keys(array_column($this->loader->profiles(), 'id'), true);
        foreach ($adapter->profileIds() as $profileId) {
            if (! isset($profileIds[$profileId])) {
                throw new InvalidArgumentException("Adapter [{$sourceId}] references unknown profile [{$profileId}].");
            }
        }

        foreach ($adapter->metricIds() as $metricId) {
            $metric = $this->metrics->get($metricId);
            if (($metric['source'] ?? null) !== $sourceId) {
                throw new InvalidArgumentException(
                    "Adapter [{$sourceId}] cannot expose metric [{$metricId}] owned by another source.",
                );
            }
        }

        $this->adapters[$sourceId] = $adapter;
    }

    /** @return array<string, IntelligenceSourceAdapter> */
    public function all(): array
    {
        return $this->adapters;
    }

    public function has(string $sourceId): bool
    {
        return isset($this->adapters[$sourceId]);
    }

    public function get(string $sourceId): ?IntelligenceSourceAdapter
    {
        return $this->adapters[$sourceId] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private function sources(): array
    {
        $sources = [];
        foreach ($this->loader->sources() as $source) {
            $sources[(string) $source['id']] = $source;
        }

        return $sources;
    }
}
