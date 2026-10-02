<?php

namespace App\Support\Integrations\Meta;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Support\Integrations\ProviderRegistry;

/**
 * Prompt 24 collection eligibility foundation (read-only).
 *
 * Eligible when: active confirmed META_AD_ACCOUNT Binding exists on a Meta Ads
 * DigitalAsset, resource is accessible, and Meta Integration is active.
 * Does not invoke collectors or Graph APIs.
 */
final class MetaAdsBindingEligibility
{
    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     binding_id: int|null,
     *     external_resource_id: int|null,
     *     integration_id: int|null
     * }
     */
    public function evaluate(DigitalAsset $asset): array
    {
        if ((string) $asset->type !== 'meta_ads') {
            return $this->deny('Digital Asset is not a Meta Ads asset.');
        }

        $binding = CoreAssetBinding::query()
            ->with(['externalResource.integration'])
            ->where('digital_asset_id', $asset->id)
            ->where('capability', MetaConnectorRegistry::META_ADS)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $binding instanceof CoreAssetBinding) {
            return $this->deny('No active human-confirmed Meta Ad Account Binding.');
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return $this->deny('Bound ExternalResource is missing.');
        }

        if ($resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
            return $this->deny('Bound resource is not a Meta Ad Account.');
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return $this->deny('Bound Ad Account is not currently accessible.');
        }

        $integration = $resource->integration;
        if (! $integration instanceof CoreIntegration
            || $integration->provider !== ProviderRegistry::META
            || $integration->status !== CoreIntegration::STATUS_ACTIVE) {
            return $this->deny('Meta Integration is not active for the bound Ad Account.');
        }

        return [
            'eligible' => true,
            'reason' => 'Active confirmed Meta Ad Account Binding is present.',
            'binding_id' => (int) $binding->id,
            'external_resource_id' => (int) $resource->id,
            'integration_id' => (int) $integration->id,
        ];
    }

    public function isEligible(DigitalAsset $asset): bool
    {
        return $this->evaluate($asset)['eligible'];
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     binding_id: int|null,
     *     external_resource_id: int|null,
     *     integration_id: int|null
     * }
     */
    private function deny(string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'binding_id' => null,
            'external_resource_id' => null,
            'integration_id' => null,
        ];
    }
}
