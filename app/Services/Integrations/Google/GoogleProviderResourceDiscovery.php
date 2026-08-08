<?php

namespace App\Services\Integrations\Google;

use App\Contracts\Integrations\DiscoversProviderResources;
use App\Models\CoreIntegration;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\ProviderRegistry;

/**
 * Discovers Google resources via capability adapters used by Refresh resources.
 */
class GoogleProviderResourceDiscovery implements DiscoversProviderResources
{
    public function __construct(
        private readonly GoogleResourceRefreshService $refreshService,
    ) {}

    public function provider(): string
    {
        return ProviderRegistry::GOOGLE;
    }

    public function discover(CoreIntegration $integration): array
    {
        $this->refreshService->refresh($integration);

        return $integration->externalResources()
            ->where('status', 'available')
            ->get()
            ->map(fn ($resource) => new DiscoveredExternalResource(
                resourceType: $resource->resource_type,
                externalId: $resource->external_id,
                displayName: $resource->display_name,
                parentExternalId: $resource->parent_external_id,
                metadata: is_array($resource->metadata) ? $resource->metadata : [],
            ))
            ->all();
    }
}
