<?php

namespace App\Support\Integrations\Meta;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Support\Integrations\ProviderRegistry;

/**
 * Thin Meta-specific Binding eligibility (Prompt 23).
 *
 * Shared Binding core stays provider-neutral; this policy only answers
 * whether a Meta Ad Account may be newly bound to a Meta Ads DigitalAsset.
 */
final class MetaBindingEligibilityPolicy
{
    public function assertEligibleResource(CoreExternalResource $resource, ?int $expectedIntegrationId = null): void
    {
        if ($resource->provider !== ProviderRegistry::META) {
            throw new \InvalidArgumentException('Only Meta ExternalResources can be bound through Meta Binding.');
        }

        if ($resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
            throw new \InvalidArgumentException(
                'Only Meta Ad Accounts can be bound as Meta Ads assets. Meta Business is discovery context, not a Binding root.',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            throw new \InvalidArgumentException(
                'This Ad Account is not accessible for a new Binding. Refresh resources or resolve access, then try again.',
            );
        }

        if ($expectedIntegrationId !== null && (int) $resource->integration_id !== $expectedIntegrationId) {
            throw new \InvalidArgumentException(
                'This Ad Account belongs to a different Meta Integration and cannot be bound here.',
            );
        }

        $integration = $resource->relationLoaded('integration')
            ? $resource->integration
            : $resource->integration()->first();

        if (! $integration instanceof CoreIntegration) {
            throw new \InvalidArgumentException('ExternalResource is missing its Meta Integration.');
        }

        if ($integration->provider !== ProviderRegistry::META) {
            throw new \InvalidArgumentException('ExternalResource must belong to the Meta Integration.');
        }

        if ($integration->status !== CoreIntegration::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Meta Integration is not active.');
        }

        $selectable = $resource->metadata['selectable'] ?? true;
        $bindable = $resource->metadata['bindable'] ?? true;
        if ($selectable === false || $bindable === false) {
            throw new \InvalidArgumentException('This Meta resource is not selectable for Binding.');
        }
    }

    public function assertEligibleAsset(DigitalAsset $asset): void
    {
        if ((string) $asset->type !== 'meta_ads') {
            throw new \InvalidArgumentException(
                'Only Meta Ads Digital Assets can receive a Meta Ad Account Binding.',
            );
        }
    }

    public function isEligibleForNewBinding(CoreExternalResource $resource, ?int $expectedIntegrationId = null): bool
    {
        try {
            $this->assertEligibleResource($resource, $expectedIntegrationId);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
