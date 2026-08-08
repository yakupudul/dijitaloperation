<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Support\Integrations\DiscoveredExternalResource;

final class CapabilityDiscoveryResult
{
    /**
     * @param  list<DiscoveredExternalResource>  $resources
     */
    public function __construct(
        public readonly string $capability,
        public readonly string $status,
        public readonly string $message,
        public readonly array $resources = [],
    ) {}

    /**
     * @param  list<DiscoveredExternalResource>  $resources
     */
    public static function ok(string $capability, array $resources, string $message = 'OK'): self
    {
        return new self($capability, 'ok', $message, $resources);
    }

    public static function setupRequired(string $capability, string $message): self
    {
        return new self($capability, 'setup_required', $message);
    }

    public static function error(string $capability, string $message): self
    {
        return new self($capability, 'error', $message);
    }

    public static function skipped(string $capability, string $message): self
    {
        return new self($capability, 'skipped', $message);
    }
}
