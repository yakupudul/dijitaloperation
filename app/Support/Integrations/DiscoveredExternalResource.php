<?php

namespace App\Support\Integrations;

/**
 * Normalized discovery result for a provider-side resource.
 * Contains no credentials.
 */
final readonly class DiscoveredExternalResource
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $resourceType,
        public string $externalId,
        public string $displayName,
        public ?string $parentExternalId = null,
        public array $metadata = [],
    ) {}
}
