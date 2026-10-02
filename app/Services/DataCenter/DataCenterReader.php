<?php

namespace App\Services\DataCenter;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Veri merkezi: every source data was collected from (an external account or a website) with the data sets stored
 * for it — rows, date range, last collection — and the brand it currently feeds (or that it is unbound / archived).
 */
final class DataCenterReader
{
    public function __construct(private readonly DataCenterCatalog $catalog) {}

    /**
     * @return list<array{key: string, kind: string, id: int, name: string, provider: string, feeds: list<string>, bound: bool,
     *     datasets: list<array{dataset: string, label: string, rows: int, from: ?string, through: ?string, collected_at: ?string, protected: bool}>,
     *     rows: int, collected_at: ?string}>
     */
    public function sources(): array
    {
        /** @var array<string, array<string, mixed>> $sources */
        $sources = [];
        $add = function (string $kind, int $id, array $dataset) use (&$sources): void {
            $key = $kind.':'.$id;
            $sources[$key] ??= ['key' => $key, 'kind' => $kind, 'id' => $id, 'datasets' => []];
            $existing = $sources[$key]['datasets'][$dataset['dataset']] ?? null;
            if ($existing !== null) {
                $dataset['rows'] += $existing['rows'];
            }
            $sources[$key]['datasets'][$dataset['dataset']] = $dataset;
        };

        if (Schema::hasTable('dataset_materializations')) {
            foreach (DB::table('dataset_materializations')->where('row_count_approx', '>', 0)
                ->get(['dataset_id', 'digital_asset_id', 'external_resource_id', 'coverage_start_date', 'coverage_end_date', 'last_collected_at', 'row_count_approx']) as $row) {
                $kind = $row->external_resource_id !== null ? 'resource' : 'asset';
                $id = (int) ($row->external_resource_id ?? $row->digital_asset_id);
                if ($id === 0) {
                    continue;
                }
                $add($kind, $id, $this->dataset((string) $row->dataset_id, (int) $row->row_count_approx, $row->coverage_start_date, $row->coverage_end_date, $row->last_collected_at));
            }
        }

        foreach (DataCenterCatalog::EXTRA_TABLES as $table => [$column, $kind]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $stamp = collect(['observed_at', 'last_collected_at', 'updated_at'])->first(fn (string $c): bool => Schema::hasColumn($table, $c));
            $select = $column.' as source_id, count(*) as n'.($stamp !== null ? ', max('.$stamp.') as last_at' : '');
            foreach (DB::table($table)->whereNotNull($column)->groupBy($column)->selectRaw($select)->get() as $row) {
                $add($kind, (int) $row->source_id, $this->dataset($table, (int) $row->n, null, null, $row->last_at ?? null));
            }
        }

        if (Schema::hasTable('raw_ingestion_objects') && Schema::hasTable('collection_resource_runs')) {
            foreach (DB::table('raw_ingestion_objects as o')->join('collection_resource_runs as r', 'r.id', '=', 'o.resource_run_id')
                ->groupBy('r.external_resource_id', 'r.digital_asset_id')
                ->selectRaw('r.external_resource_id, r.digital_asset_id, count(*) as n, max(o.captured_at) as last_at')->get() as $row) {
                $kind = $row->external_resource_id !== null ? 'resource' : 'asset';
                $id = (int) ($row->external_resource_id ?? $row->digital_asset_id);
                if ($id > 0) {
                    $add($kind, $id, $this->dataset(DataCenterCatalog::RAW, (int) $row->n, null, null, $row->last_at));
                }
            }
        }

        return $this->describe($sources);
    }

    /**
     * @return array{dataset: string, label: string, rows: int, from: ?string, through: ?string, collected_at: ?string, protected: bool}
     */
    private function dataset(string $dataset, int $rows, mixed $from, mixed $through, mixed $collectedAt): array
    {
        return [
            'dataset' => $dataset,
            'label' => $this->catalog->label($dataset),
            'rows' => $rows,
            'from' => $from !== null ? substr((string) $from, 0, 10) : null,
            'through' => $through !== null ? substr((string) $through, 0, 10) : null,
            'collected_at' => $collectedAt !== null ? substr((string) $collectedAt, 0, 16) : null,
            'protected' => $this->catalog->isProtected($dataset),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function describe(array $sources): array
    {
        $resourceIds = array_values(array_map(fn (array $s): int => $s['id'], array_filter($sources, fn (array $s): bool => $s['kind'] === 'resource')));
        $assetIds = array_values(array_map(fn (array $s): int => $s['id'], array_filter($sources, fn (array $s): bool => $s['kind'] === 'asset')));
        $resources = CoreExternalResource::query()->whereIn('id', $resourceIds)->get()->keyBy('id');
        $assets = DigitalAsset::withTrashed()->with(['brand' => fn ($q) => $q->withTrashed()])->whereIn('id', $assetIds)->get()->keyBy('id');
        $feeds = [];
        foreach (CoreAssetBinding::query()->whereIn('external_resource_id', $resourceIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with(['digitalAsset.brand'])->get() as $binding) {
            $brand = $binding->digitalAsset?->brand;
            if ($brand !== null) {
                $feeds[(int) $binding->external_resource_id][$brand->id] = (string) $brand->name;
            }
        }

        $out = [];
        foreach ($sources as $source) {
            if ($source['kind'] === 'resource') {
                $resource = $resources->get($source['id']);
                $source['name'] = $resource !== null ? (string) ($resource->display_name ?: $resource->external_id) : 'Silinmiş hesap #'.$source['id'];
                $source['provider'] = $this->resourceProvider((string) ($resource?->resource_type ?? ''), $source['datasets']);
                $source['feeds'] = array_values($feeds[$source['id']] ?? []);
                $source['bound'] = $source['feeds'] !== [];
            } else {
                $asset = $assets->get($source['id']);
                $source['name'] = $asset !== null ? (string) ($asset->domain ?: $asset->name) : 'Silinmiş varlık #'.$source['id'];
                $source['provider'] = $asset?->type === 'website' || $asset === null ? 'Web sitesi' : ucfirst(str_replace('_', ' ', (string) $asset->type));
                $brand = $asset?->brand;
                $source['feeds'] = $brand !== null ? [(string) $brand->name.($brand->trashed() || $asset->trashed() ? ' (arşivde)' : '')] : [];
                $source['bound'] = $brand !== null && ! $brand->trashed() && ! $asset->trashed();
            }
            $datasets = array_values($source['datasets']);
            usort($datasets, fn (array $a, array $b): int => [$a['protected'], $a['label']] <=> [$b['protected'], $b['label']]);
            $source['datasets'] = $datasets;
            $source['rows'] = array_sum(array_column($datasets, 'rows'));
            $source['collected_at'] = collect($datasets)->pluck('collected_at')->filter()->max();
            $out[] = $source;
        }
        usort($out, fn (array $a, array $b): int => [$a['provider'], mb_strtolower($a['name'])] <=> [$b['provider'], mb_strtolower($b['name'])]);

        return $out;
    }

    /** @param array<string, array<string, mixed>> $datasets */
    private function resourceProvider(string $type, array $datasets): string
    {
        return match ($type) {
            'search_console' => 'Search Console',
            'ga4', 'ga4_property' => 'GA4',
            'google_ads' => 'Google Ads',
            'meta_ads', 'meta_ad_account' => 'Meta Ads',
            'google_business_profile' => 'İşletme Profili',
            default => $this->catalog->provider((string) (array_key_first($datasets) ?? '')),
        };
    }
}
