<?php

namespace App\Services\GoogleAds;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\GoogleAds\Support\GoogleAdsBindingContext;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Time\SafeTimezone;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the Google Ads workspace binding for an assetId (Demo catalog string OR
 * numeric DigitalAsset id). Only the human-confirmed active `google_ads`
 * CoreAssetBinding is used — never the first-accessible Customer or manager child.
 */
final class GoogleAdsSpecialistBindingResolver
{
    public const string CAPABILITY = 'google_ads';

    /** Relations every check below reads: the account, its integration and the integration's two stored credentials. */
    private const array WITH = ['externalResource.integration.providerCredential', 'externalResource.integration.authorizationCredential'];

    public function resolve(string $assetId): GoogleAdsBindingContext
    {
        if (! ctype_digit($assetId)) {
            return GoogleAdsBindingContext::demoCatalog($assetId);
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
     * (with account, integration and credentials) and one account snapshot query for all of them.
     *
     * @param  list<int>  $assetIds
     * @return array<int, GoogleAdsBindingContext> keyed by asset id, in the given order
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
     * @param  array<string, object>|null  $snapshots  "asset id|customer id" => newest account snapshot (resolveMany), null: read it here
     */
    private function context(string $assetId, int $digitalAssetId, ?CoreAssetBinding $binding, ?array $snapshots = null): GoogleAdsBindingContext
    {
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

        [$timezone, $currency] = $this->resolveTimezoneAndCurrency($resource, $digitalAssetId, $customerId, $snapshots);

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
     * Newest account snapshot per asset × customer of the given assets, in one query (empty when the table is missing).
     *
     * @param  list<int>  $assetIds
     * @return array<string, object> "asset id|customer id" => snapshot row (source_timezone, metadata)
     */
    private function snapshots(array $assetIds): array
    {
        $out = [];
        try {
            foreach (DB::table('google_ads_account_snapshot')->whereIn('digital_asset_id', $assetIds)->orderByDesc('id')
                ->get(['digital_asset_id', 'customer_id', 'source_timezone', 'metadata']) as $row) {
                $out[(int) $row->digital_asset_id.'|'.$row->customer_id] ??= $row;
            }
        } catch (\Throwable) {
            // Snapshot table may be missing — resource metadata remains authoritative fallback.
        }

        return $out;
    }

    /**
     * @param  array<string, object>|null  $snapshots  pre-read snapshots (resolveMany), null: read this one
     * @return array{0: string, 1: string}
     */
    private function resolveTimezoneAndCurrency(
        CoreExternalResource $resource,
        int $digitalAssetId,
        string $customerId,
        ?array $snapshots = null,
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
            $snapshot = $snapshots !== null ? ($snapshots[$digitalAssetId.'|'.$customerId] ?? null) : DB::table('google_ads_account_snapshot')
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

        return [SafeTimezone::normalize($timezone), $currency];
    }
}
