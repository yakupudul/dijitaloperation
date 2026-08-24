<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionBindingScope;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Google\GoogleCredentialBroker;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;

/**
 * Shared authorization boundary for bound and resource-first Google Ads collection.
 *
 * Managers/MCCs are access context only. Performance collection always targets a
 * concrete non-manager Google Ads customer.
 */
final class GoogleAdsEligibilityGuard
{
    public function __construct(
        private readonly GoogleScopeCoverageService $coverage,
        private readonly GoogleCredentialBroker $broker,
        private readonly GoogleCredentialResolver $credentials,
    ) {}

    /**
     * @return array{
     *   binding: CoreAssetBinding|null,
     *   asset: DigitalAsset|null,
     *   resource: CoreExternalResource,
     *   integration: CoreIntegration,
     *   customer_id: string,
     *   login_customer_id: string,
     *   is_manager: bool,
     *   currency_code: ?string,
     *   time_zone: ?string,
     *   collection_scope: 'bound_asset'|'provider_resource_first'
     * }|DatasetExecutionResult
     */
    public function assertEligible(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        if ($this->isCentralScope($collectionRun, $resourceRun)) {
            return $this->centralScope($collectionRun, $resourceRun);
        }

        return $this->boundScope($collectionRun, $resourceRun);
    }

    private function isCentralScope(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): bool
    {
        return $resourceRun->core_asset_binding_id === null
            && $resourceRun->digital_asset_id === null
            && $collectionRun->digital_asset_id === null
            && data_get($resourceRun->metadata, 'collection_scope') === 'provider_resource_first'
            && data_get($collectionRun->request_context, 'context.collection_scope') === 'provider_resource_first';
    }

    /** @return array<string,mixed>|DatasetExecutionResult */
    private function centralScope(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        if ($collectionRun->customer_id !== null || $collectionRun->brand_id !== null || $collectionRun->digital_asset_id !== null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads resource-first collection must not be scoped to a Customer, Brand, or DigitalAsset.',
                'CENTRAL_SCOPE_MISMATCH',
            );
        }

        $resource = CoreExternalResource::query()
            ->with('integration')
            ->find($resourceRun->external_resource_id);
        $integration = $resource?->integration;

        if (! $resource instanceof CoreExternalResource || ! $integration instanceof CoreIntegration) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads provider resource graph is incomplete.',
                'SCOPE_GRAPH_INCOMPLETE',
            );
        }

        $integrationId = (int) data_get($collectionRun->request_context, 'context.google_integration_id');
        if ($integrationId <= 0
            || $integrationId !== (int) $integration->id
            || (int) $resourceRun->external_resource_id !== (int) $resource->id
            || $resourceRun->provider_or_source !== 'GOOGLE_ADS') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Cross-tenant protection: Google Ads provider-resource-first scope mismatch.',
                'CROSS_TENANT',
            );
        }

        $common = $this->providerScope($resource, $integration);
        if ($common instanceof DatasetExecutionResult) {
            return $common;
        }

        return array_merge($common, [
            'binding' => null,
            'asset' => null,
            'collection_scope' => 'provider_resource_first',
        ]);
    }

    /** @return array<string,mixed>|DatasetExecutionResult */
    private function boundScope(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        $bindingId = $resourceRun->core_asset_binding_id;
        if ($bindingId === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads bound collection requires an active CoreAssetBinding.',
                'BINDING_REQUIRED',
            );
        }

        $binding = CoreAssetBinding::query()
            ->with(['digitalAsset.brand', 'externalResource.integration'])
            ->find($bindingId);

        if (! $binding instanceof CoreAssetBinding || $binding->status !== CoreAssetBinding::STATUS_ACTIVE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads binding is missing or not active.',
                'BINDING_INACTIVE',
            );
        }
        if ($binding->capability !== GoogleScopeRegistry::CAPABILITY_GOOGLE_ADS) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Binding capability is not google_ads.',
                'CONTRACT_MISMATCH',
            );
        }

        $asset = $binding->digitalAsset;
        $resource = $binding->externalResource;
        $integration = $resource?->integration;
        if (! $asset instanceof DigitalAsset || ! $resource instanceof CoreExternalResource || ! $integration instanceof CoreIntegration) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads binding scope graph is incomplete.',
                'SCOPE_GRAPH_INCOMPLETE',
            );
        }

        if (! CollectionBindingScope::collectionRunMayTargetAsset($collectionRun, $asset)
            || (int) $resourceRun->digital_asset_id !== (int) $asset->id
            || (int) $resourceRun->external_resource_id !== (int) $resource->id
            || (int) $resourceRun->core_asset_binding_id !== (int) $binding->id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Cross-tenant protection: Google Ads scope mismatch.',
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

        $common = $this->providerScope($resource, $integration);
        if ($common instanceof DatasetExecutionResult) {
            return $common;
        }

        return array_merge($common, [
            'binding' => $binding,
            'asset' => $asset,
            'collection_scope' => 'bound_asset',
        ]);
    }

    /** @return array<string,mixed>|DatasetExecutionResult */
    private function providerScope(CoreExternalResource $resource, CoreIntegration $integration): array|DatasetExecutionResult
    {
        if ($resource->resource_type !== GoogleResourceType::GOOGLE_ADS_CUSTOMER) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::ContractMismatch, 'ExternalResource is not a Google Ads customer.', 'RESOURCE_TYPE_MISMATCH');
        }
        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Authorization, 'Google Ads ExternalResource is not available.', 'RESOURCE_UNAVAILABLE');
        }
        if ($integration->provider !== ProviderRegistry::GOOGLE || ! $integration->isActive()) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Authentication, 'Google Integration is not active.', 'INTEGRATION_INACTIVE');
        }

        $auth = GoogleAuthStatus::for($integration);
        if (in_array($auth, [
            GoogleAuthStatus::NOT_CONFIGURED,
            GoogleAuthStatus::AUTHORIZATION_REQUIRED,
            GoogleAuthStatus::REVOKED,
            GoogleAuthStatus::DISABLED,
            GoogleAuthStatus::REFRESH_REQUIRED,
        ], true)) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Authentication, 'Google Integration authorization is not usable for Google Ads collection.', 'AUTHENTICATION_REQUIRED');
        }

        $granted = $this->coverage->grantedScopes($integration);
        if ($granted !== [] && ! $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_GOOGLE_ADS)) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Authorization, 'Google Ads OAuth scope is required before provider calls.', 'SCOPE_REQUIRED');
        }

        $developerToken = $this->broker->adsDeveloperToken($integration)
            ?? $this->credentials->developerToken($integration);
        if ($developerToken === null || $developerToken === '') {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Authorization, 'Google Ads developer token application configuration is missing.', 'DEVELOPER_TOKEN_REQUIRED');
        }

        $customerId = preg_replace('/\D+/', '', (string) $resource->external_id) ?? '';
        if ($customerId === '') {
            return DatasetExecutionResult::failed(CollectionErrorCategory::ContractMismatch, 'Google Ads Customer provider identity is missing on ExternalResource.', 'CUSTOMER_ID_MISSING');
        }

        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $isManager = (bool) ($metadata['is_manager'] ?? false);
        if ($isManager || array_key_exists('selectable', $metadata) && ! (bool) $metadata['selectable']) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Authorization, 'Manager Google Ads accounts are navigation/access context, not performance collection roots.', 'MANAGER_NOT_PERFORMANCE_ROOT');
        }

        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id']
            ?? $metadata['manager_customer_id']
            ?? $customerId)) ?? $customerId;
        if ($login === '') {
            $login = $customerId;
        }

        return [
            'resource' => $resource,
            'integration' => $integration,
            'customer_id' => $customerId,
            'login_customer_id' => $login,
            'is_manager' => false,
            'currency_code' => isset($metadata['currency_code']) ? (string) $metadata['currency_code'] : null,
            'time_zone' => isset($metadata['time_zone'])
                ? (string) $metadata['time_zone']
                : (isset($metadata['timezone']) ? (string) $metadata['timezone'] : null),
        ];
    }
}
