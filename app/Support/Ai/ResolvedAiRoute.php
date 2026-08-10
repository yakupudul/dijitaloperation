<?php

namespace App\Support\Ai;

/**
 * Resolved, secret-free AI route plan for Laravel failover chains.
 */
final class ResolvedAiRoute
{
    /**
     * @param  array<string, string>  $providerModels  ordered map provider => model
     * @param  list<array{provider: string, model: string, role: string, eligible: bool, reason: ?string}>  $steps
     */
    public function __construct(
        public readonly string $routeKey,
        public readonly string $routeName,
        public readonly array $providerModels,
        public readonly array $steps,
        public readonly string $signature,
        public readonly bool $usingPersistedSteps,
    ) {}

    public function primaryProvider(): ?string
    {
        $keys = array_keys($this->providerModels);

        return $keys[0] ?? null;
    }

    public function primaryModel(): ?string
    {
        $provider = $this->primaryProvider();

        return $provider !== null ? ($this->providerModels[$provider] ?? null) : null;
    }

    public function isEmpty(): bool
    {
        return $this->providerModels === [];
    }
}
