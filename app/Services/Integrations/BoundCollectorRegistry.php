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
        $existing = $this->byCapability[$capability] ?? null;

        // Laravel can boot the same provider more than once while rebuilding the
        // package/service manifest during deploy. Re-registering the exact same
        // collector class is harmless and must not make composer package:discover fail.
        if ($existing instanceof CollectsBoundProviderData) {
            if ($existing::class === $collector::class) {
                return;
            }

            throw new LogicException(sprintf(
                'Bound collector already registered for capability: %s (%s, attempted %s)',
                $capability,
                $existing::class,
                $collector::class,
            ));
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
