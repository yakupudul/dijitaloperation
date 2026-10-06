<?php

namespace App\Services\Observability;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Support\Operator\CollectionErrorExplainer;
use Throwable;

/**
 * Names the things an aggregated system alert is about: for each affected account (or asset) its brand, asset,
 * account name, source, the datasets involved and the last collection error. Stored in the alert's `observed` so
 * every screen can say "which accounts, which data, why" without re-querying the collection tables.
 */
final class AlertSubjects
{
    /** Affected entries kept on the alert (the text shows 5 and "+N"). */
    public const int KEEP = 20;

    /**
     * @param  list<array{asset_id?: ?int, resource_id?: ?int, dataset?: ?string, error_category?: ?string, state?: ?string}>  $rows
     * @return list<array{asset_id: ?int, asset: ?string, asset_type: ?string, brand_id: ?int, brand: ?string, resource_id: ?int, account: ?string, source: ?string, provider: string, integration_id: ?int, account_email: ?string, automation_id: ?int, datasets: list<string>, states: list<string>, error_category: ?string}>
     */
    public function describe(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $resourceId = isset($row['resource_id']) && $row['resource_id'] !== null ? (int) $row['resource_id'] : null;
            $assetId = isset($row['asset_id']) && (int) $row['asset_id'] > 0 ? (int) $row['asset_id'] : null;
            $key = $resourceId !== null ? 'r'.$resourceId : 'a'.($assetId ?? 0);
            $groups[$key] ??= ['asset_id' => $assetId, 'resource_id' => $resourceId, 'datasets' => [], 'states' => [], 'error_category' => null];
            $groups[$key]['asset_id'] ??= $assetId;
            if (filled($row['dataset'] ?? null)) {
                $groups[$key]['datasets'][] = (string) $row['dataset'];
            }
            if (filled($row['state'] ?? null)) {
                $groups[$key]['states'][] = (string) $row['state'];
            }
            $groups[$key]['error_category'] ??= filled($row['error_category'] ?? null) ? (string) $row['error_category'] : null;
        }
        $groups = array_slice($groups, 0, self::KEEP);

        try {
            $resources = CoreExternalResource::query()->with('integration')
                ->whereIn('id', array_filter(array_column($groups, 'resource_id')))->get()->keyBy('id');
            $assetIds = array_filter(array_column($groups, 'asset_id'));
            // An account without an asset on the row: take the asset it is bound to.
            $boundAsset = CoreAssetBinding::query()->whereIn('external_resource_id', $resources->keys())
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)->orderByDesc('id')->get(['external_resource_id', 'digital_asset_id'])
                ->unique('external_resource_id')->pluck('digital_asset_id', 'external_resource_id');
            $assets = DigitalAsset::query()->whereIn('id', array_merge($assetIds, $boundAsset->values()->all()))->get(['id', 'name', 'domain', 'type', 'brand_id'])->keyBy('id');
            $brands = Brand::query()->whereIn('id', $assets->pluck('brand_id')->filter())->pluck('name', 'id');
            $automations = ResourceAutomation::query()->whereIn('external_resource_id', $resources->keys())->pluck('id', 'external_resource_id');
        } catch (Throwable) {
            $resources = collect();
            $boundAsset = collect();
            $assets = collect();
            $brands = collect();
            $automations = collect();
        }

        $out = [];
        foreach ($groups as $group) {
            $resource = $group['resource_id'] !== null ? $resources->get($group['resource_id']) : null;
            $assetId = $group['asset_id'] ?? ($group['resource_id'] !== null ? $boundAsset->get($group['resource_id']) : null);
            $asset = $assetId !== null ? $assets->get((int) $assetId) : null;
            $source = $resource?->resource_type ?? $asset?->type;
            $config = is_array($resource?->integration?->config) ? $resource->integration->config : [];
            $out[] = [
                'asset_id' => $asset !== null ? (int) $asset->id : ($assetId !== null ? (int) $assetId : null),
                'asset' => $asset !== null ? (string) ($asset->domain ?: $asset->name) : null,
                'asset_type' => $asset?->type,
                'brand_id' => $asset?->brand_id !== null ? (int) $asset->brand_id : null,
                'brand' => $asset?->brand_id !== null ? $brands->get($asset->brand_id) : null,
                'resource_id' => $group['resource_id'],
                'account' => $resource?->display_name,
                'source' => $source !== null ? (string) $source : null,
                'provider' => CollectionErrorExplainer::providerOf($source),
                'integration_id' => $resource?->integration_id !== null ? (int) $resource->integration_id : null,
                'account_email' => is_string($config['account_email'] ?? null) ? $config['account_email'] : null,
                'automation_id' => $group['resource_id'] !== null && $automations->has($group['resource_id']) ? (int) $automations->get($group['resource_id']) : null,
                'datasets' => array_values(array_unique($group['datasets'])),
                'states' => array_values(array_unique($group['states'])),
                // A late dataset is explained by its own error, never by another dataset of the same account.
                'error_category' => $group['error_category'] ?? ($group['resource_id'] !== null
                    ? $this->lastErrorCategory($group['resource_id'], array_values(array_unique($group['datasets']))) : null),
            ];
        }

        return $out;
    }

    /**
     * The account's current collection error in the last week: per dataset (and Search Console search type) only its
     * latest run counts, and only while that run failed or waits for a retry. A step that was retried and then
     * completed, or a failure a later run of the same dataset fixed, no longer names the cause. With `$datasets` only
     * those datasets are read (the late ones of a "veri güncel değil" alert); without, every dataset of the account.
     * Null when nothing is failing.
     *
     * @param  list<string>  $datasets
     */
    public function lastErrorCategory(int $resourceId, array $datasets = []): ?string
    {
        try {
            $latest = CollectionDatasetRun::query()->selectRaw('max(id)')
                ->where('created_at', '>=', now()->subDays(7))
                ->whereHas('resourceRun', fn ($q) => $q->where('external_resource_id', $resourceId))
                ->when($datasets !== [], fn ($q) => $q->whereIn('dataset_contract_id', $datasets))
                ->groupBy('dataset_contract_id', 'execution_variant');
            $category = CollectionDatasetRun::query()->whereIn('id', $latest)
                ->whereIn('status', [CollectionRunStatus::Failed->value, CollectionRunStatus::Retrying->value])
                ->whereNotNull('error_category')
                ->orderByDesc('id')->value('error_category');
        } catch (Throwable) {
            return null;
        }

        return $category instanceof \BackedEnum ? (string) $category->value : ($category !== null ? (string) $category : null);
    }
}
