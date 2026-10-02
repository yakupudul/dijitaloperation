<?php

namespace App\Services\Collection\Providers\Website;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\PageSpeedConnectionProbeService;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use MoxDop\Website\SeoIntelligence\WebsiteDomainTarget;

/**
 * Website production collection is asset-scoped. Sibling Google/Meta bindings
 * never participate. PageSpeed is conditional on an enabled site-scoped connection.
 */
final class WebsiteEligibilityGuard
{
    /**
     * @return array{
     *   asset: DigitalAsset,
     *   seed_url: string,
     *   host: string,
     *   pagespeed_connection: ?CoreConnection
     * }|DatasetExecutionResult
     */
    public function assertEligible(CollectionRun $collectionRun, CollectionResourceRun $resourceRun): array|DatasetExecutionResult
    {
        if ($resourceRun->core_asset_binding_id !== null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Website production collection does not use provider External Resource bindings.',
                'BINDING_NOT_USED',
            );
        }

        $assetId = $resourceRun->digital_asset_id !== null
            ? (int) $resourceRun->digital_asset_id
            : (int) $collectionRun->digital_asset_id;

        if ($assetId !== (int) $collectionRun->digital_asset_id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Website collection cannot target a sibling Digital Asset.',
                'ASSET_SCOPE_MISMATCH',
            );
        }

        $asset = DigitalAsset::query()->with('brand')->find($assetId);
        if (! $asset instanceof DigitalAsset) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Website Digital Asset is missing.',
                'ASSET_MISSING',
            );
        }

        if ((string) $asset->type !== 'website') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Digital Asset is not a Website.',
                'ASSET_TYPE_MISMATCH',
            );
        }

        if ($collectionRun->brand_id !== null && (int) $asset->brand_id !== (int) $collectionRun->brand_id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Website Digital Asset is outside the CollectionRun Brand.',
                'BRAND_SCOPE_MISMATCH',
            );
        }

        $seedUrl = $this->seedUrl($asset);
        if ($seedUrl === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Website primary URL or domain is required for production collection.',
                'SEED_URL_REQUIRED',
            );
        }

        $host = WebsiteDomainTarget::fromAsset($asset);
        if ($host === null) {
            $parsed = parse_url($seedUrl, PHP_URL_HOST);
            $host = is_string($parsed) ? strtolower($parsed) : null;
        }

        if ($host === null || $host === '') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Website host could not be resolved.',
                'HOST_REQUIRED',
            );
        }

        $pagespeed = CoreConnection::query()
            ->with('credential')
            ->where('digital_asset_id', $asset->id)
            ->where('type', PageSpeedConnectionProbeService::CONNECTION_TYPE)
            ->where('enabled', true)
            ->orderBy('id')
            ->first();

        return [
            'asset' => $asset,
            'seed_url' => $seedUrl,
            'host' => $host,
            'pagespeed_connection' => $pagespeed instanceof CoreConnection ? $pagespeed : null,
        ];
    }

    private function seedUrl(DigitalAsset $asset): ?string
    {
        $normalizer = new PublicUrlNormalizer;
        foreach ([$asset->primary_url, $asset->domain] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $value = trim($candidate);
            if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
                $value = 'https://'.$value;
            }
            $normalized = $normalizer->normalizeAbsolute($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}
