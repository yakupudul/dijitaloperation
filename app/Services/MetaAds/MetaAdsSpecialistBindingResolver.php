<?php

namespace App\Services\MetaAds;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\MetaAds\Support\MetaAdsBindingContext;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Resolves Meta Ads workspace binding. Analytical root = META_AD_ACCOUNT only.
 */
final class MetaAdsSpecialistBindingResolver
{
    public const string CAPABILITY = MetaConnectorRegistry::META_ADS;

    public function resolve(string $assetId): MetaAdsBindingContext
    {
        if (! ctype_digit($assetId)) {
            return MetaAdsBindingContext::demoCatalog($assetId);
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
            return MetaAdsBindingContext::notConnected($assetId, $digitalAssetId);
        }

        $resource = $binding->externalResource;
        if (! $resource instanceof CoreExternalResource) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                null,
                $binding->id,
                'binding_scope_incomplete',
            );
        }

        if ($resource->resource_type === MetaResourceType::META_BUSINESS) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'meta_business_not_analytical_root',
            );
        }

        if ($resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'resource_type_mismatch',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'resource_unavailable',
            );
        }

        $integration = $resource->integration;
        if (! $integration instanceof CoreIntegration
            || $integration->provider !== ProviderRegistry::META
            || ! $integration->isActive()
        ) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'integration_inactive',
            );
        }

        if (MetaAuthStatus::for($integration) !== MetaAuthStatus::CONNECTED) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'authorization_not_ready',
            );
        }

        $externalId = trim((string) $resource->external_id);
        if ($externalId === '') {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'account_id_missing',
            );
        }

        $accountId = MetaAdAccountId::digits($externalId);
        if ($accountId === null) {
            return MetaAdsBindingContext::actionRequired(
                $assetId,
                $digitalAssetId,
                $resource->id,
                $binding->id,
                'account_id_invalid',
            );
        }

        $actId = (string) MetaAdAccountId::canonical($accountId);
        [$timezone, $currency] = $this->resolveTimezoneAndCurrency($resource, $digitalAssetId, $accountId);

        return MetaAdsBindingContext::realBound(
            $assetId,
            $digitalAssetId,
            $resource->id,
            $binding->id,
            $accountId,
            $actId,
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
        string $accountId,
    ): array {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = (string) ($meta['timezone_name'] ?? $meta['time_zone'] ?? $meta['timezone'] ?? 'UTC');
        $currency = strtoupper((string) ($meta['currency'] ?? $meta['currency_code'] ?? 'XXX'));

        try {
            $snapshot = DB::table('meta_ad_account_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('account_id', $accountId)
                ->orderByDesc('id')
                ->first(['source_timezone', 'metadata']);

            if ($snapshot !== null) {
                if (filled($snapshot->source_timezone ?? null)) {
                    $timezone = (string) $snapshot->source_timezone;
                }
                $snapMeta = is_string($snapshot->metadata)
                    ? json_decode($snapshot->metadata, true)
                    : (is_array($snapshot->metadata) ? $snapshot->metadata : []);
                if (is_array($snapMeta) && filled($snapMeta['currency'] ?? null)) {
                    $currency = strtoupper((string) $snapMeta['currency']);
                }
            }
        } catch (\Throwable) {
        }

        if ($currency === '' || $currency === 'XXX') {
            try {
                $dailyCurrency = DB::table('meta_campaign_daily')
                    ->where('digital_asset_id', $digitalAssetId)
                    ->where('account_id', $accountId)
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
