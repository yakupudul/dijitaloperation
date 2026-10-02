<?php

namespace App\Services\IntelligenceCore;

final class IntelligenceCapabilityRegistry
{
    public function __construct(
        private readonly IntelligenceCoreRegistryLoader $loader,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $capabilities = [];
        foreach ($this->loader->capabilities() as $capability) {
            $capabilities[(string) $capability['id']] = $capability;
        }

        return $capabilities;
    }

    public function has(string $capabilityId): bool
    {
        return isset($this->all()[$capabilityId]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $capabilityId): ?array
    {
        return $this->all()[$capabilityId] ?? null;
    }

    /** @return list<string> */
    public function forProfile(string $profileId): array
    {
        $ids = [];
        foreach ($this->all() as $capability) {
            if (in_array($profileId, $capability['profiles'] ?? [], true)) {
                $ids[] = (string) $capability['id'];
            }
        }

        return $ids;
    }
}
