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
use App\Support\Time\SafeTimezone;
use Illuminate\Support\Facades\DB;

/**
 * Resolves Meta Ads workspace binding. Analytical root = META_AD_ACCOUNT only.
 */
final class MetaAdsSpecialistBindingResolver
{
    public const string CAPABILITY = MetaConnectorRegistry::META_ADS;

    /** Relations every check below reads: the account, its integration and the integration's stored credential. */
    private const array WITH = ['externalResource.integration.providerCredential'];

    public function resolve(string $assetId): MetaAdsBindingContext
    {
        if (! ctype_digit($assetId)) {
            return MetaAdsBindingContext::demoCatalog($assetId);
        }

        $digitalAssetId = (int) $assetId;

        $binding = CoreAssetBinding::query()
            ->with(self::WITH)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('capability', self::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        return $this->context($assetId, $digitalAssetId, $binding instanceof CoreAssetBinding ? $binding : null);
    }

    /**
     * The binding of several digital assets with the same checks as resolve(), read in one batch: one binding query
     * (with account, integration and credential) and one ad account snapshot query for all of them, instead of about
     * six queries per asset.
     *
     * @param  list<int>  $assetIds
     * @return array<int, MetaAdsBindingContext> keyed by asset id, in the given order
     */
    public function resolveMany(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        if ($assetIds === []) {
            return [];
        }
        $bindings = CoreAssetBinding::query()
            ->with(self::WITH)
            ->whereIn('digital_asset_id', $assetIds)
            ->where('capability', self::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->unique('digital_asset_id')
            ->keyBy('digital_asset_id');
        $snapshots = $bindings->isEmpty() ? [] : $this->snapshots($bindings->keys()->map(fn ($id): int => (int) $id)->all());

        $out = [];
        foreach ($assetIds as $assetId) {
            $out[$assetId] = $this->context((string) $assetId, $assetId, $bindings->get($assetId), $snapshots);
        }

        return $out;
    }

    /**
     * @param  array<string, object|null>|null  $snapshots  "asset id|account id" => newest ad account snapshot (resolveMany), null: read it here
     */
    private function context(string $assetId, int $digitalAssetId, ?CoreAssetBinding $binding, ?array $snapshots = null): MetaAdsBindingContext
    {
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
        [$timezone, $currency] = $this->resolveTimezoneAndCurrency($resource, $digitalAssetId, $accountId, $snapshots);

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
     * Newest ad account snapshot per asset × account of the given assets, in one query (empty when the table is missing).
     *
     * @param  list<int>  $assetIds
     * @return array<string, object> "asset id|account id" => snapshot row (source_timezone, metadata)
     */
    private function snapshots(array $assetIds): array
    {
        $out = [];
        try {
            foreach (DB::table('meta_ad_account_snapshot')->whereIn('digital_asset_id', $assetIds)->orderByDesc('id')
                ->get(['digital_asset_id', 'account_id', 'source_timezone', 'metadata']) as $row) {
                $out[(int) $row->digital_asset_id.'|'.$row->account_id] ??= $row;
            }
        } catch (\Throwable) {
        }

        return $out;
    }

    /**
     * @param  array<string, object|null>|null  $snapshots  pre-read snapshots (resolveMany), null: read this one
     * @return array{0: string, 1: string}
     */
    private function resolveTimezoneAndCurrency(
        CoreExternalResource $resource,
        int $digitalAssetId,
        string $accountId,
        ?array $snapshots = null,
    ): array {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = (string) ($meta['timezone_name'] ?? $meta['time_zone'] ?? $meta['timezone'] ?? 'UTC');
        $currency = strtoupper((string) ($meta['currency'] ?? $meta['currency_code'] ?? 'XXX'));

        try {
            $snapshot = $snapshots !== null ? ($snapshots[$digitalAssetId.'|'.$accountId] ?? null) : DB::table('meta_ad_account_snapshot')
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

        return [SafeTimezone::normalize($timezone), $currency];
    }
}
