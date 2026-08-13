<?php

namespace App\Services\GoogleAds;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\GoogleAds\Support\GoogleAdsBindingContext;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the Google Ads workspace binding for an assetId (Demo catalog string OR
 * numeric DigitalAsset id). Only the human-confirmed active `google_ads`
 * CoreAssetBinding is used — never the first-accessible Customer or manager child.
 */
final class GoogleAdsSpecialistBindingResolver
{
    public const string CAPABILITY = 'google_ads';

    public function resolve(string $assetId): GoogleAdsBindingContext
    {
        if (! ctype_digit($assetId)) {
            return GoogleAdsBindingContext::demoCatalog($assetId);
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
            return GoogleAdsBindingContext::notConnected($assetId, $digitalAssetId);
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return GoogleAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                null,
                $binding->id,
                'binding_scope_incomplete',
            );
        }

        $integration = $resource->integration;

        if ($resource->resource_type !== GoogleResourceType::GOOGLE_ADS_CUSTOMER) {
            return GoogleAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'resource_type_mismatch',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return GoogleAdsBindingContext::actionRequired(
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
            return GoogleAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'integration_inactive',
            );
        }

        if (GoogleAuthStatus::for($integration) !== GoogleAuthStatus::CONNECTED) {
            return GoogleAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'authorization_not_ready',
            );
        }

        $customerId = trim((string) $resource->external_id);
        if ($customerId === '') {
            return GoogleAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'customer_id_missing',
            );
        }

        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        if (($metadata['manager'] ?? $metadata['is_manager'] ?? false) === true) {
            return GoogleAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'manager_not_analytical_root',
            );
        }

        [$timezone, $currency] = $this->resolveTimezoneAndCurrency($resource, $digitalAssetId, $customerId);

        return GoogleAdsBindingContext::realBound(
            $assetId,
            $digitalAssetId,
            $resource->id,
            $binding->id,
            $customerId,
            $timezone,
            $currency,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveTimezoneAndCurrency(
        CoreExternalResource $resource,
        int $digitalAssetId,
        string $customerId,
    ): array {
        $timezone = 'UTC';
        $currency = 'XXX';

        $resourceMetadata = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = (string) ($resourceMetadata['timezone']
            ?? $resourceMetadata['time_zone']
            ?? $timezone);
        $currency = strtoupper((string) ($resourceMetadata['currency']
            ?? $resourceMetadata['currency_code']
            ?? $currency));

        try {
            $snapshot = DB::table('google_ads_account_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('customer_id', $customerId)
                ->orderByDesc('id')
                ->first(['source_timezone', 'metadata']);

            if ($snapshot !== null) {
                if (filled($snapshot->source_timezone ?? null)) {
                    $timezone = (string) $snapshot->source_timezone;
                }
                $meta = is_string($snapshot->metadata)
                    ? json_decode($snapshot->metadata, true)
                    : (is_array($snapshot->metadata) ? $snapshot->metadata : []);
                if (is_array($meta)) {
                    if (filled($meta['time_zone'] ?? null)) {
                        $timezone = (string) $meta['time_zone'];
                    }
                    if (filled($meta['currency_code'] ?? $meta['currency'] ?? null)) {
                        $currency = strtoupper((string) ($meta['currency_code'] ?? $meta['currency']));
                    }
                }
            }
        } catch (\Throwable) {
            // Snapshot table may be empty — resource metadata remains authoritative fallback.
        }

        if ($currency === '' || $currency === 'XXX') {
            try {
                $dailyCurrency = DB::table('google_ads_account_daily')
                    ->where('digital_asset_id', $digitalAssetId)
                    ->where('customer_id', $customerId)
                    ->whereNotNull('currency')
                    ->orderByDesc('reporting_date')
                    ->value('currency');
                if (is_string($dailyCurrency) && $dailyCurrency !== '') {
                    $currency = strtoupper($dailyCurrency);
                }
            } catch (\Throwable) {
            }
        }

        return [$timezone, $currency];
    }
}
