<?php

namespace App\Services\Gsc;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleProviderCapabilities;
use App\Services\Gsc\Support\GscBindingContext;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;

/**
 * Resolves the GSC workspace binding for an assetId (Demo catalog string OR
 * numeric DigitalAsset id). Only the human-confirmed active `search_console`
 * CoreAssetBinding is used — never the first-available ExternalResource by name.
 */
final class GscSpecialistBindingResolver
{
    public const string CAPABILITY = 'search_console';

    public function resolve(string $assetId): GscBindingContext
    {
        if (! ctype_digit($assetId)) {
            return GscBindingContext::demoCatalog($assetId);
        }

        $digitalAssetId = (int) $assetId;

        $binding = CoreAssetBinding::query()
            ->with(['externalResource.integration'])
            ->where('digital_asset_id', $digitalAssetId)
            ->where('capability', self::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $binding instanceof CoreAssetBinding) {
            return GscBindingContext::notConnected($assetId, $digitalAssetId);
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return GscBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                null,
                $binding->id,
                'binding_scope_incomplete',
            );
        }

        $integration = $resource->integration;

        if ($resource->resource_type !== GoogleResourceType::GSC_PROPERTY) {
            return GscBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'resource_type_mismatch',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return GscBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'resource_unavailable',
            );
        }

        if (! $integration instanceof CoreIntegration
            || $integration->provider !== ProviderRegistry::GOOGLE
            || ! $integration->isActive()
        ) {
            return GscBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'integration_inactive',
            );
        }

        if (GoogleAuthStatus::for($integration) !== GoogleAuthStatus::CONNECTED) {
            return GscBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'authorization_not_ready',
            );
        }

        $siteUrl = trim((string) $resource->external_id);
        if ($siteUrl === '') {
            return GscBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'site_url_missing',
            );
        }

        $timezone = $this->resolveTimezone($resource);

        return GscBindingContext::realBound(
            $assetId,
            $digitalAssetId,
            $resource->id,
            $binding->id,
            $siteUrl,
            $timezone,
        );
    }

    private function resolveTimezone(CoreExternalResource $resource): string
    {
        $resourceMetadata = is_array($resource->metadata) ? $resource->metadata : [];

        return (string) (
            $resourceMetadata['reporting_timezone']
            ?? $resourceMetadata['timezone']
            ?? SearchConsoleProviderCapabilities::REPORTING_TIMEZONE
        );
    }
}
