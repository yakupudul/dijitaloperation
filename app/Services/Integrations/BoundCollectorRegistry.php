<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\CollectsBoundProviderData;
use LogicException;

final class BoundCollectorRegistry
{
    /** @var array<string, CollectsBoundProviderData> */
    private array $byCapability = [];

    public function register(CollectsBoundProviderData $collector): void
    {
        $capability = $collector->capability();
        if (isset($this->byCapability[$capability])) {
            throw new LogicException('Bound collector already registered for capability: '.$capability);
        }

        $this->byCapability[$capability] = $collector;
    }

    public function forCapability(string $capability): ?CollectsBoundProviderData
    {
        return $this->byCapability[$capability] ?? null;
    }

    /**
     * @return array<string, CollectsBoundProviderData>
     */
    public function all(): array
    {
        return $this->byCapability;
    }
}
