<?php

namespace MoxDop\MetaAds\Support;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Collection;

/**
 * Operator workspace summaries for Meta Ads Digital Assets (connection layer V1).
 *
 * @phpstan-type MetaAdsWorkspaceSummary array{
 *     asset: DigitalAsset,
 *     active_binding: ?CoreAssetBinding,
 *     bound_resource: ?CoreExternalResource,
 *     integration: ?CoreIntegration,
 *     integration_configured: bool,
 *     bindable_resources: Collection<int, CoreExternalResource>,
 *     connection_label: string,
 *     account_label: string
 * }
 */
final class MetaAdsWorkspaceData
{
    /**
     * @return MetaAdsWorkspaceSummary
     */
    public static function forAsset(DigitalAsset $asset): array
    {
        $asset->loadMissing(['brand.customer', 'assetBindings.externalResource.integration']);

        $activeBinding = $asset->assetBindings
            ->first(fn (CoreAssetBinding $binding): bool => $binding->status === CoreAssetBinding::STATUS_ACTIVE
                && $binding->capability === 'meta_ads');

        $boundResource = $activeBinding?->externalResource;
        $integration = self::resolvePreferredIntegration($boundResource);

        $bindableResources = collect();
        if ($integration !== null) {
            $bindableResources = CoreExternalResource::query()
                ->where('integration_id', $integration->id)
                ->where('provider', ProviderRegistry::META)
                ->where('resource_type', 'meta_ads')
                ->where('status', CoreExternalResource::STATUS_AVAILABLE)
                ->orderBy('display_name')
                ->get();
        }

        $configured = $integration !== null
            && $integration->providerCredential()->exists();

        return [
            'asset' => $asset,
            'active_binding' => $activeBinding,
            'bound_resource' => $boundResource,
            'integration' => $integration,
            'integration_configured' => $configured,
            'bindable_resources' => $bindableResources,
            'connection_label' => $integration !== null ? 'Connected' : 'Not connected',
            'account_label' => $boundResource?->display_name
                ?? ($boundResource?->external_id !== null
                    ? (string) $boundResource->external_id
                    : 'Not bound'),
        ];
    }

    public static function isCompatibleResource(DigitalAsset $asset, CoreExternalResource $resource): bool
    {
        return AssetBindingCompatibility::isCompatible($asset, $resource)
            && $resource->provider === ProviderRegistry::META;
    }

    private static function resolvePreferredIntegration(?CoreExternalResource $boundResource): ?CoreIntegration
    {
        if ($boundResource?->integration !== null) {
            return $boundResource->integration;
        }

        return CoreIntegration::query()
            ->where('provider', ProviderRegistry::META)
            ->where('status', '!=', CoreIntegration::STATUS_DISABLED)
            ->orderBy('id')
            ->first();
    }
}
