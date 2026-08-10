<?php

namespace App\Services\Integrations\Meta;

use App\Contracts\Integrations\DiscoversProviderResources;
use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;

/**
 * DiscoversProviderResources adapter for Meta Ad Accounts.
 */
class MetaProviderResourceDiscovery implements DiscoversProviderResources
{
    public function __construct(
        private readonly MetaResourceDiscoveryService $discovery,
    ) {}

    public function provider(): string
    {
        return ProviderRegistry::META;
    }

    public function discover(CoreIntegration $integration): array
    {
        return $this->discovery->discover($integration)['resources'];
    }
}
