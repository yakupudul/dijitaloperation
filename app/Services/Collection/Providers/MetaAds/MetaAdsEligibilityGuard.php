<?php

namespace App\Services\Collection\Providers\MetaAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaPermissionCoverageService;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaPermissionRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;

/**
 * Meta Ads collection scope: a human-confirmed active Binding, or (MoxDOP v2: every discovered account is collected)
 * the discovered META_AD_ACCOUNT itself for a provider-resource-first run (no asset). Both need ads_read coverage.
 */
final class MetaAdsEligibilityGuard
{
    public function __construct(
        private readonly MetaPermissionCoverageService $permissions,
        private readonly MetaCredentialResolver $credentials,
    ) {}

    /**
     * @return array{
     *   binding: CoreAssetBinding|null,
     *   asset: DigitalAsset|null,
     *   resource: CoreExternalResource,
     *   integration: CoreIntegration,
     *   account_id: string,
     *   act_id: string,
     *   currency: ?string,
     *   time_zone: ?string
     * }|DatasetExecutionResult
     */
    public function assertEligible(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        $bindingId = $resourceRun->core_asset_binding_id;
        if ($bindingId === null && $resourceRun->external_resource_id !== null
            && data_get($resourceRun->metadata, 'collection_scope') === 'provider_resource_first') {
            $resource = CoreExternalResource::query()->with('integration')->find($resourceRun->external_resource_id);
            if (! $resource instanceof CoreExternalResource || ! $resource->integration instanceof CoreIntegration) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Meta Ad Account resource is missing.',
                    'SCOPE_GRAPH_INCOMPLETE',
                );
            }

            return $this->accountScope(null, null, $resource, $resource->integration);
        }
        if ($bindingId === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Meta Ads production collection requires a human-confirmed CoreAssetBinding.',
                'BINDING_REQUIRED',
            );
        }

        $binding = CoreAssetBinding::query()
            ->with(['digitalAsset.brand', 'externalResource.integration'])
            ->find($bindingId);

        if (! $binding instanceof CoreAssetBinding || $binding->status !== CoreAssetBinding::STATUS_ACTIVE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Meta Ads binding is missing or not active.',
                'BINDING_INACTIVE',
            );
        }

        if ($binding->capability !== MetaConnectorRegistry::META_ADS) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Binding capability is not meta_ads.',
                'CONTRACT_MISMATCH',
            );
        }

        $asset = $binding->digitalAsset;
        $resource = $binding->externalResource;
        $integration = $resource?->integration;

        if (! $asset instanceof DigitalAsset || ! $resource instanceof CoreExternalResource || ! $integration instanceof CoreIntegration) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Meta Ads binding scope graph is incomplete.',
                'SCOPE_GRAPH_INCOMPLETE',
            );
        }

        if ((string) $asset->type !== 'meta_ads') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Digital Asset is not a Meta Ads asset.',
                'ASSET_TYPE_MISMATCH',
            );
        }

        return $this->accountScope($binding, $asset, $resource, $integration);
    }

    /** @return array<string, mixed>|DatasetExecutionResult */
    private function accountScope(?CoreAssetBinding $binding, ?DigitalAsset $asset, CoreExternalResource $resource, CoreIntegration $integration): array|DatasetExecutionResult
    {

        if ($resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'ExternalResource is not a Meta Ad Account. META_BUSINESS is not an analytical collection root.',
                'RESOURCE_TYPE_MISMATCH',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Meta Ad Account ExternalResource is not available.',
                'RESOURCE_UNAVAILABLE',
            );
        }

        if ($integration->provider !== ProviderRegistry::META || $integration->status !== CoreIntegration::STATUS_ACTIVE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Meta Integration is not active.',
                'INTEGRATION_INACTIVE',
            );
        }

        $auth = MetaAuthStatus::for($integration);
        if (in_array($auth, [
            MetaAuthStatus::NOT_CONFIGURED,
            MetaAuthStatus::AUTHORIZATION_REQUIRED,
            MetaAuthStatus::REAUTH_REQUIRED,
        ], true)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Meta authorization is required before analytical collection.',
                'REAUTH_REQUIRED',
            );
        }

        if ($this->credentials->accessToken($integration) === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Meta access token is not available.',
                'TOKEN_MISSING',
            );
        }

        if ($this->permissions->hasValidatedGrantSet($integration)) {
            $missing = MetaPermissionRegistry::missing(
                $this->permissions->grantedPermissions($integration),
                ['ads_read'],
            );
            if ($missing !== []) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Meta ads_read permission is required for analytical collection.',
                    'PERMISSION_REQUIRED',
                );
            }
        }

        $actId = MetaAdAccountId::canonical((string) $resource->external_id);
        $digits = MetaAdAccountId::digits((string) $resource->external_id);
        if ($actId === null || $digits === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Meta Ad Account identity is not canonical act_* form.',
                'ACCOUNT_ID_INVALID',
            );
        }

        $meta = is_array($resource->metadata) ? $resource->metadata : [];

        return [
            'binding' => $binding,
            'asset' => $asset,
            'resource' => $resource,
            'integration' => $integration,
            'account_id' => $digits,
            'act_id' => $actId,
            'currency' => isset($meta['currency']) && is_string($meta['currency']) ? $meta['currency'] : null,
            'time_zone' => isset($meta['timezone_name']) && is_string($meta['timezone_name'])
                ? $meta['timezone_name']
                : 'UTC',
        ];
    }
}
