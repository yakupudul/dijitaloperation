<?php

namespace App\Services\Collection\Providers\SearchConsole;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use RuntimeException;

/**
 * Production GSC collection may run only for human-confirmed active bindings.
 */
final class SearchConsoleEligibilityGuard
{
    public function __construct(
        private readonly GoogleScopeCoverageService $coverage,
    ) {}

    /**
     * @return array{
     *   binding: CoreAssetBinding,
     *   asset: DigitalAsset,
     *   resource: CoreExternalResource,
     *   integration: CoreIntegration,
     *   site_url: string
     * }|DatasetExecutionResult
     */
    public function assertEligible(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        $bindingId = $resourceRun->core_asset_binding_id;
        if ($bindingId === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GSC production collection requires a human-confirmed CoreAssetBinding.',
                'BINDING_REQUIRED',
            );
        }

        $binding = CoreAssetBinding::query()
            ->with(['digitalAsset.brand', 'externalResource.integration'])
            ->find($bindingId);

        if (! $binding instanceof CoreAssetBinding) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GSC binding not found.',
                'BINDING_MISSING',
            );
        }

        if ($binding->status !== CoreAssetBinding::STATUS_ACTIVE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GSC binding is not active.',
                'BINDING_INACTIVE',
            );
        }

        if ($binding->capability !== GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Binding capability is not search_console.',
                'CONTRACT_MISMATCH',
            );
        }

        $asset = $binding->digitalAsset;
        $resource = $binding->externalResource;
        $integration = $resource?->integration;

        if (! $asset instanceof DigitalAsset || ! $resource instanceof CoreExternalResource || ! $integration instanceof CoreIntegration) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GSC binding scope graph is incomplete.',
                'SCOPE_GRAPH_INCOMPLETE',
            );
        }

        if ($resource->resource_type !== GoogleResourceType::GSC_PROPERTY) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'ExternalResource is not a Search Console property.',
                'RESOURCE_TYPE_MISMATCH',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'GSC ExternalResource is not available.',
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
                'Google Integration authorization is not usable for GSC collection.',
                'AUTHENTICATION_REQUIRED',
            );
        }

        $granted = $this->coverage->grantedScopes($integration);
        if ($granted !== [] && ! $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Search Console OAuth scope is required before provider calls.',
                'SCOPE_REQUIRED',
            );
        }

        if ((int) $collectionRun->digital_asset_id !== (int) $asset->id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Cross-tenant protection: CollectionRun DigitalAsset mismatch.',
                'CROSS_TENANT',
            );
        }

        if ((int) $resourceRun->digital_asset_id !== (int) $asset->id
            || (int) $resourceRun->external_resource_id !== (int) $resource->id
            || (int) $resourceRun->core_asset_binding_id !== (int) $binding->id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Cross-tenant protection: ResourceRun scope mismatch.',
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

        $siteUrl = trim((string) $resource->external_id);
        if ($siteUrl === '') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'GSC property provider identity (siteUrl) is missing on ExternalResource.',
                'SITE_URL_MISSING',
            );
        }

        return [
            'binding' => $binding,
            'asset' => $asset,
            'resource' => $resource,
            'integration' => $integration,
            'site_url' => $siteUrl,
        ];
    }

    public function assertInspectionUrlBelongsToProperty(string $siteUrl, string $inspectionUrl): void
    {
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            $domain = substr($siteUrl, strlen('sc-domain:'));
            $host = parse_url($inspectionUrl, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                throw new RuntimeException('Inspection URL host is missing.');
            }
            $host = strtolower($host);
            $domain = strtolower($domain);
            if ($host !== $domain && ! str_ends_with($host, '.'.$domain)) {
                throw new RuntimeException('Inspection URL is outside the GSC domain property.');
            }

            return;
        }

        $normalizedSite = rtrim($siteUrl, '/').'/';
        if (! str_starts_with($inspectionUrl, $siteUrl) && ! str_starts_with($inspectionUrl, $normalizedSite)) {
            // URL-prefix properties require the inspection URL to start with siteUrl.
            if (! str_starts_with($inspectionUrl, rtrim($siteUrl, '/'))) {
                throw new RuntimeException('Inspection URL is outside the GSC URL-prefix property.');
            }
        }
    }
}
