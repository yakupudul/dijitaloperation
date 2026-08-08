<?php

namespace App\Services\Integrations;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\ProviderRegistry;
use RuntimeException;

/**
 * Provider-agnostic Binding preflight for collectors.
 * Google token validity is checked only when provider is Google.
 */
final class BoundCollectionGuard
{
    public function __construct(
        private readonly GoogleOAuthService $googleOAuth,
    ) {}

    /**
     * @return array{
     *     binding: CoreAssetBinding,
     *     asset: DigitalAsset,
     *     resource: CoreExternalResource,
     *     integration: CoreIntegration
     * }
     */
    public function assertCollectable(CoreAssetBinding $binding, string $expectedCapability): array
    {
        $binding->loadMissing(['digitalAsset', 'externalResource.integration']);

        if ($binding->status !== CoreAssetBinding::STATUS_ACTIVE) {
            throw new RuntimeException('Binding is not active.');
        }

        if ($binding->capability !== $expectedCapability) {
            throw new RuntimeException('Binding capability does not match collector.');
        }

        $asset = $binding->digitalAsset;
        if (! $asset instanceof DigitalAsset) {
            throw new RuntimeException('Digital Asset is missing for this binding.');
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            throw new RuntimeException('External Resource is missing for this binding.');
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            throw new RuntimeException('External Resource is unavailable.');
        }

        if ($resource->resource_type !== $expectedCapability) {
            throw new RuntimeException('External Resource type does not match binding capability.');
        }

        if (! AssetBindingCompatibility::isCompatible($asset, $resource)) {
            throw new RuntimeException('Digital Asset type is incompatible with this External Resource.');
        }

        $integration = $resource->integration;
        if (! $integration instanceof CoreIntegration) {
            throw new RuntimeException('Integration is missing for this External Resource.');
        }

        if (! $integration->isActive()) {
            throw new RuntimeException('Integration is disabled.');
        }

        if ($integration->provider === ProviderRegistry::GOOGLE) {
            if ($this->googleOAuth->validAccessToken($integration) === null) {
                throw new RuntimeException('Google authorization is missing or expired. Authorize Google again.');
            }
        }

        return [
            'binding' => $binding,
            'asset' => $asset,
            'resource' => $resource,
            'integration' => $integration,
        ];
    }
}
