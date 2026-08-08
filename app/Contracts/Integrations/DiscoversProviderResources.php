<?php

namespace App\Contracts\Integrations;

use App\Models\CoreIntegration;
use App\Support\Integrations\DiscoveredExternalResource;

/**
 * Provider Integration resource discovery contract.
 *
 * Implementations must be read-only against external APIs and must never
 * return credential material inside DiscoveredExternalResource metadata.
 */
interface DiscoversProviderResources
{
    public function provider(): string;

    /**
     * @return list<DiscoveredExternalResource>
     */
    public function discover(CoreIntegration $integration): array;
}
