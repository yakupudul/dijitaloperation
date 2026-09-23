<?php

namespace App\Services\Operator;

use App\Models\AdvisorItem;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Support\Integrations\AssetBindingCompatibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real connection / freshness / open-work status for a list of Digital Assets, in a fixed number of
 * queries (no per-row lookups). Freshness = the newest successful (completed or partial) data pull of any
 * bound account, or of the website crawl for Website assets.
 */
final class AssetRuntimeStatusReader
{
    public const STALE_AFTER_HOURS = 72;

    /**
     * @param  Collection<int, DigitalAsset>  $assets
     * @return array<int, array{connected: bool, last_sync: ?CarbonImmutable, data_state: string, data_state_label: string, last_update: string, open_tasks: int, collectable: bool}>
     */
    public function forAssets(Collection $assets): array
    {
        $ids = $assets->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $bindings = CoreAssetBinding::query()
            ->whereIn('digital_asset_id', $ids)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->get(['digital_asset_id', 'external_resource_id']);
        $resourceIds = $bindings->pluck('external_resource_id')->filter()->unique()->values()->all();
        $resourceSync = $this->resourceSync($resourceIds);
        $websiteSync = $this->websiteSync($ids);
        $work = $this->openWork($ids);

        $out = [];
        foreach ($assets as $asset) {
            $id = (int) $asset->id;
            $assetBindings = $bindings->where('digital_asset_id', $id);
            $syncs = $assetBindings->map(fn ($binding) => $resourceSync[(int) $binding->external_resource_id] ?? null)->filter();
            if (isset($websiteSync[$id])) {
                $syncs->push($websiteSync[$id]);
            }
            $lastSync = $syncs->sortDesc()->first();
            $collectable = AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) !== [] || $asset->type === 'website';
            $connected = $assetBindings->isNotEmpty();

            $state = match (true) {
                ! $collectable => 'not_applicable',
                $lastSync === null => 'unavailable',
                $lastSync->lt(now()->subHours(self::STALE_AFTER_HOURS)) => 'stale',
                default => 'fresh',
            };
            $label = match ($state) {
                'fresh' => __('operator.states.fresh'),
                'stale' => __('operator.states.stale'),
                'not_applicable' => __('operator.states.not_applicable'),
                default => $connected ? __('operator.states.not_collected') : __('operator.states.not_connected'),
            };

            $out[$id] = [
                'connected' => $connected,
                'last_sync' => $lastSync,
                'data_state' => $state,
                'data_state_label' => $label,
                'last_update' => $lastSync?->diffForHumans() ?? __('operator.states.never_updated'),
                'open_tasks' => $work[$id] ?? 0,
                'collectable' => $collectable,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $resourceIds
     * @return array<int, CarbonImmutable>
     */
    private function resourceSync(array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [];
        }
        $out = [];
        $merge = function (int $resourceId, mixed $value) use (&$out): void {
            if ($value === null) {
                return;
            }
            $at = CarbonImmutable::parse((string) $value);
            if (! isset($out[$resourceId]) || $at->gt($out[$resourceId])) {
                $out[$resourceId] = $at;
            }
        };

        if (Schema::hasTable('collection_resource_runs')) {
            DB::table('collection_resource_runs')
                ->whereIn('external_resource_id', $resourceIds)
                ->whereIn('status', ['completed', 'partial'])
                ->whereNotNull('finished_at')
                ->groupBy('external_resource_id')
                ->selectRaw('external_resource_id, max(finished_at) as finished_at')
                ->get()
                ->each(fn (object $row) => $merge((int) $row->external_resource_id, $row->finished_at));
        }
        // Business Profile is collected by its own bound collector; its snapshots carry the capture time.
        DB::table('gbp_location_snapshots')
            ->whereIn('external_resource_id', $resourceIds)
            ->groupBy('external_resource_id')
            ->selectRaw('external_resource_id, max(captured_at) as captured_at')
            ->get()
            ->each(fn (object $row) => $merge((int) $row->external_resource_id, $row->captured_at));

        return $out;
    }

    /**
     * @param  list<int>  $assetIds
     * @return array<int, CarbonImmutable>
     */
    private function websiteSync(array $assetIds): array
    {
        if (! Schema::hasTable('collection_runs')) {
            return [];
        }

        return DB::table('collection_runs')
            ->whereIn('digital_asset_id', $assetIds)
            ->whereIn('status', ['completed', 'partial'])
            ->whereNotNull('finished_at')
            ->groupBy('digital_asset_id')
            ->selectRaw('digital_asset_id, max(finished_at) as finished_at')
            ->pluck('finished_at', 'digital_asset_id')
            ->mapWithKeys(fn ($value, $id): array => [(int) $id => CarbonImmutable::parse((string) $value)])
            ->all();
    }

    /**
     * Open advisor recommendations + open SEO tasks per asset.
     *
     * @param  list<int>  $assetIds
     * @return array<int, int>
     */
    private function openWork(array $assetIds): array
    {
        $out = [];
        foreach ([AdvisorItem::query()->open(), SeoTask::query()->open()] as $query) {
            $query->whereIn('digital_asset_id', $assetIds)
                ->groupBy('digital_asset_id')
                ->selectRaw('digital_asset_id, count(*) as aggregate')
                ->pluck('aggregate', 'digital_asset_id')
                ->each(function ($count, $id) use (&$out): void {
                    $out[(int) $id] = ($out[(int) $id] ?? 0) + (int) $count;
                });
        }

        return $out;
    }
}
