<?php

namespace App\Support\Integrations;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Support\Integrations\Google\GoogleConnectorRegistry;

/**
 * Validates Binding scope consistency without performing provider HTTP.
 *
 * Agency Google Integration is shared (provider unique). Tenant safety means:
 * - ExternalResource must belong to the expected Integration/provider
 * - DigitalAsset type must be compatible with resource capability
 * - Resource provider must match Connector provider for the capability
 */
final class BindingScopeGuard
{
    public static function assertCanBind(DigitalAsset $asset, CoreExternalResource $resource): void
    {
        if ($resource->provider === ProviderRegistry::GOOGLE) {
            $connector = GoogleConnectorRegistry::byResourceType((string) $resource->resource_type);
            if ($connector === null) {
                throw new \InvalidArgumentException('Bu Google hesap türü tanınmıyor; bağlanamaz.');
            }
        }

        if (! AssetBindingCompatibility::isCompatible($asset, $resource)) {
            throw new \InvalidArgumentException('Bu hesap bu dijital varlık türüne bağlanamaz.');
        }

        if (! ExternalResourceAssetCompatibility::canBindResourceToAssetType(
            (string) $resource->resource_type,
            (string) $asset->type,
        )) {
            throw new \InvalidArgumentException('Bu hesap bu dijital varlık türüne bağlanamaz.');
        }
    }

    public static function belongsToIntegration(CoreExternalResource $resource, int $integrationId): bool
    {
        return (int) $resource->integration_id === $integrationId;
    }

    public static function bindingMatchesResourceIntegration(CoreAssetBinding $binding): bool
    {
        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return false;
        }

        if ($resource->resource_type === $binding->capability) {
            return true;
        }

        $connector = GoogleConnectorRegistry::byCapability($binding->capability);

        return $connector !== null
            && $connector['resource_type'] === $resource->resource_type;
    }
}
