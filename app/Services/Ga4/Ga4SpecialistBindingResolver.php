<?php

namespace App\Services\Ga4;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Ga4\Support\Ga4BindingContext;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the GA4 workspace binding for an assetId (Demo catalog string OR
 * numeric DigitalAsset id). Only the human-confirmed active `ga4` CoreAssetBinding
 * is used — never the first-available ExternalResource by name.
 */
final class Ga4SpecialistBindingResolver
{
    public const string CAPABILITY = 'ga4';

    public function resolve(string $assetId): Ga4BindingContext
    {
        if (! ctype_digit($assetId)) {
            return Ga4BindingContext::demoCatalog($assetId);
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
            return Ga4BindingContext::notConnected($assetId, $digitalAssetId);
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return Ga4BindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                null,
                $binding->id,
                'binding_scope_incomplete',
            );
        }

        $integration = $resource->integration;

        if ($resource->resource_type !== GoogleResourceType::GA4_PROPERTY) {
            return Ga4BindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'resource_type_mismatch',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return Ga4BindingContext::actionRequired(
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
            return Ga4BindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'integration_inactive',
            );
        }

        if (GoogleAuthStatus::for($integration) !== GoogleAuthStatus::CONNECTED) {
            return Ga4BindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'authorization_not_ready',
            );
        }

        $externalId = trim((string) $resource->external_id);
        if ($externalId === '') {
            return Ga4BindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'property_id_missing',
            );
        }

        $propertyId = preg_replace('/^properties\//', '', $externalId) ?? $externalId;

        return Ga4BindingContext::realBound(
            $assetId,
            $digitalAssetId,
            $resource->id,
            $binding->id,
            $propertyId,
            $this->resolveTimezone($digitalAssetId, $propertyId, $resource),
        );
    }

    private function resolveTimezone(int $digitalAssetId, string $propertyId, CoreExternalResource $resource): string
    {
        $metadataRow = DB::table('ga4_property_metadata')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('property_id', $propertyId)
            ->first();

        if ($metadataRow !== null) {
            if (filled($metadataRow->source_timezone ?? null)) {
                return (string) $metadataRow->source_timezone;
            }

            $metadata = is_string($metadataRow->metadata ?? null)
                ? json_decode((string) $metadataRow->metadata, true)
                : null;
            if (is_array($metadata) && filled($metadata['time_zone'] ?? null)) {
                return (string) $metadata['time_zone'];
            }
        }

        $resourceMetadata = is_array($resource->metadata) ? $resource->metadata : [];

        return (string) ($resourceMetadata['timezone'] ?? $resourceMetadata['time_zone'] ?? 'UTC');
    }
}
