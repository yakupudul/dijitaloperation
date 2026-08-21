<?php

namespace App\Services\Collection\Providers\Ga4;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionBindingScope;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;

/**
 * GA4 can be collected directly from a discovered provider resource into the central Data Pool.
 * A Digital Asset binding is optional at ingestion time; when present, tenant-scope checks remain strict.
 */
final class Ga4EligibilityGuard
{
    public function __construct(
        private readonly GoogleScopeCoverageService $coverage,
    ) {}

    /**
     * @return array{
     *   binding: CoreAssetBinding|null,
     *   asset: DigitalAsset|null,
     *   resource: CoreExternalResource,
     *   integration: CoreIntegration,
     *   property_id: string,
     *   property_resource_name: string,
     *   collection_scope: string
     * }|DatasetExecutionResult
     */
    public function assertEligible(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        $binding = null;
        $asset = null;
        $resource = null;
        $integration = null;

        if ($resourceRun->core_asset_binding_id !== null) {
            $binding = CoreAssetBinding::query()
                ->with(['digitalAsset.brand', 'externalResource.integration'])
                ->find($resourceRun->core_asset_binding_id);

            if (! $binding instanceof CoreAssetBinding || $binding->status !== CoreAssetBinding::STATUS_ACTIVE) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'GA4 binding is missing or not active.',
                    'BINDING_INACTIVE',
                );
            }

            if ($binding->capability !== GoogleScopeRegistry::CAPABILITY_GA4) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::ContractMismatch,
                    'Binding capability is not ga4.',
                    'CONTRACT_MISMATCH',
                );
            }

            $asset = $binding->digitalAsset;
            $resource = $binding->externalResource;
            $integration = $resource?->integration;

            if (! $asset instanceof DigitalAsset || ! $resource instanceof CoreExternalResource || ! $integration instanceof CoreIntegration) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'GA4 binding scope graph is incomplete.',
                    'SCOPE_GRAPH_INCOMPLETE',
                );
            }

            if (! CollectionBindingScope::collectionRunMayTargetAsset($collectionRun, $asset)
                || (int) $resourceRun->digital_asset_id !== (int) $asset->id
                || (int) $resourceRun->external_resource_id !== (int) $resource->id
                || (int) $resourceRun->core_asset_binding_id !== (int) $binding->id) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Cross-tenant protection: GA4 scope mismatch.',
                    'CROSS_TENANT',
                );
            }

            if ($collectionRun->brand_id !== null && (int) $collectionRun->brand_id !== (int) $asset->brand_id) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Cross-tenant protection: Brand mismatch.',
                    'CROSS_TENANT',
                );
            }
        } else {
            $resourceId = $resourceRun->external_resource_id;
            if ($resourceId === null) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::ContractMismatch,
                    'GA4 resource-first collection requires an ExternalResource.',
                    'EXTERNAL_RESOURCE_REQUIRED',
                );
            }

            $resource = CoreExternalResource::query()
                ->with('integration')
                ->find($resourceId);
            $integration = $resource?->integration;

            if (! $resource instanceof CoreExternalResource || ! $integration instanceof CoreIntegration) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'GA4 provider-resource scope graph is incomplete.',
                    'SCOPE_GRAPH_INCOMPLETE',
                );
            }

            if ($resourceRun->digital_asset_id !== null
                || $collectionRun->digital_asset_id !== null
                || $collectionRun->brand_id !== null
                || $collectionRun->customer_id !== null) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Resource-first GA4 collection cannot silently inherit Customer, Brand or Digital Asset scope.',
                    'CENTRAL_SCOPE_MISMATCH',
                );
            }

            $intent = (string) data_get($collectionRun->request_context, 'context.collection_scope', '');
            if ($intent !== 'provider_resource_first') {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Authorization,
                    'Unbound GA4 collection is allowed only for explicit provider-resource-first runs.',
                    'CENTRAL_SCOPE_REQUIRED',
                );
            }
        }

        if ($resource->provider !== ProviderRegistry::GOOGLE
            || $resource->resource_type !== GoogleResourceType::GA4_PROPERTY) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'ExternalResource is not a Google GA4 property.',
                'RESOURCE_TYPE_MISMATCH',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GA4 ExternalResource is not available.',
                'RESOURCE_UNAVAILABLE',
            );
        }

        if ($integration->provider !== ProviderRegistry::GOOGLE || ! $integration->isActive()) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Google Integration is not active.',
                'INTEGRATION_INACTIVE',
            );
        }

        $auth = GoogleAuthStatus::for($integration);
        if (in_array($auth, [
            GoogleAuthStatus::NOT_CONFIGURED,
            GoogleAuthStatus::AUTHORIZATION_REQUIRED,
            GoogleAuthStatus::REVOKED,
            GoogleAuthStatus::DISABLED,
            GoogleAuthStatus::REFRESH_REQUIRED,
        ], true)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'Google Integration authorization is not usable for GA4 collection.',
                'AUTHENTICATION_REQUIRED',
            );
        }

        $granted = $this->coverage->grantedScopes($integration);
        if ($granted !== [] && ! $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_GA4)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GA4 OAuth scope is required before provider calls.',
                'SCOPE_REQUIRED',
            );
        }

        $externalId = trim((string) $resource->external_id);
        if ($externalId === '') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'GA4 Property provider identity is missing on ExternalResource.',
                'PROPERTY_ID_MISSING',
            );
        }

        $propertyResourceName = str_starts_with($externalId, 'properties/')
            ? $externalId
            : 'properties/'.$externalId;
        $propertyId = preg_replace('/^properties\//', '', $propertyResourceName) ?? $externalId;

        return [
            'binding' => $binding,
            'asset' => $asset,
            'resource' => $resource,
            'integration' => $integration,
            'property_id' => $propertyId,
            'property_resource_name' => $propertyResourceName,
            'collection_scope' => $binding instanceof CoreAssetBinding ? 'digital_asset_binding' : 'provider_resource_first',
        ];
    }
}
