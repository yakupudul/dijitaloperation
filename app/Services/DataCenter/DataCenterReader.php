<?php

namespace App\Services\DataCenter;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Veri merkezi: every source data was collected from (an external account or a website) with the data sets stored
 * for it — rows, date range, last collection — and the brand it currently feeds (or that it is unbound / archived).
 *
 * Counting the rows means a full GROUP BY over every source table, so the counts are kept in the cache: the hourly
 * RefreshDataCenterSummaryJob (background queue) and every erase rewrite them, the screen only reads them. Names,
 * brands, labels and protection are read live, so a new binding or a protection change shows at once.
 */
final class DataCenterReader
{
    /** The cached row counts (see compute()); kept a day so a stopped scheduler falls back to one live count. */
    public const string CACHE_KEY = 'data-center:sources:v1';

    public function __construct(private readonly DataCenterCatalog $catalog) {}

    /**
     * @return list<array{key: string, kind: string, id: int, name: string, provider: string, feeds: list<string>, bound: bool,
     *     datasets: list<array{dataset: string, label: string, rows: int, from: ?string, through: ?string, collected_at: ?string, protected: bool}>,
     *     rows: int, collected_at: ?string}>
     */
    public function sources(): array
    {
        $counts = Cache::get(self::CACHE_KEY);

        return $this->describe(is_array($counts) ? $counts : $this->refresh());
    }

    /**
     * Counts every source's rows again and keeps the result in the cache for a day.
     *
     * @return array<string, array{key: string, kind: string, id: int, datasets: array<string, array{rows: int, from: ?string, through: ?string, collected_at: ?string}>}>
     */
    public function refresh(): array
    {
        $counts = $this->compute();
        Cache::put(self::CACHE_KEY, $counts, now()->addDay());

        return $counts;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The stored rows per source and data set: data-pool materializations, the tables written outside the registry
     * (DataCenterCatalog::EXTRA_TABLES) and raw provider payloads. Full table aggregations: run it in the background.
     *
     * @return array<string, array{key: string, kind: string, id: int, datasets: array<string, array{rows: int, from: ?string, through: ?string, collected_at: ?string}>}>
     */
    public function compute(): array
    {
        $tables = array_flip(Schema::getTableListing(Schema::getCurrentSchemaName(), false));
        /** @var array<string, array{key: string, kind: string, id: int, datasets: array<string, array{rows: int, from: ?string, through: ?string, collected_at: ?string}>}> $sources */
        $sources = [];
        $add = function (string $kind, int $id, string $dataset, int $rows, mixed $from, mixed $through, mixed $collectedAt) use (&$sources): void {
            $key = $kind.':'.$id;
            $sources[$key] ??= ['key' => $key, 'kind' => $kind, 'id' => $id, 'datasets' => []];
            $existing = $sources[$key]['datasets'][$dataset] ?? null;
            $entry = ['rows' => $rows, 'from' => self::stamp($from, 10), 'through' => self::stamp($through, 10), 'collected_at' => self::stamp($collectedAt, 16)];
            if ($existing !== null) {
                $entry = [
                    'rows' => $existing['rows'] + $entry['rows'],
                    'from' => min(array_filter([$existing['from'], $entry['from']]) ?: [null]),
                    'through' => max([$existing['through'], $entry['through']]),
                    'collected_at' => max([$existing['collected_at'], $entry['collected_at']]),
                ];
            }
            $sources[$key]['datasets'][$dataset] = $entry;
        };

        if (isset($tables['dataset_materializations'])) {
            foreach (DB::table('dataset_materializations')->where('row_count_approx', '>', 0)
                ->get(['dataset_id', 'digital_asset_id', 'external_resource_id', 'coverage_start_date', 'coverage_end_date', 'last_collected_at', 'row_count_approx']) as $row) {
                $kind = $row->external_resource_id !== null ? 'resource' : 'asset';
                $id = (int) ($row->external_resource_id ?? $row->digital_asset_id);
                if ($id === 0) {
                    continue;
                }
                $add($kind, $id, (string) $row->dataset_id, (int) $row->row_count_approx, $row->coverage_start_date, $row->coverage_end_date, $row->last_collected_at);
            }
        }

        foreach (DataCenterCatalog::EXTRA_TABLES as $table => [$column, $kind, , $stamp]) {
            if (! isset($tables[$table])) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull($column)->groupBy($column)->selectRaw($column.' as source_id, count(*) as n, max('.$stamp.') as last_at')->get() as $row) {
                $add($kind, (int) $row->source_id, $table, (int) $row->n, null, null, $row->last_at);
            }
        }

        if (isset($tables['raw_ingestion_objects'], $tables['collection_resource_runs'])) {
            foreach (DB::table('raw_ingestion_objects as o')->join('collection_resource_runs as r', 'r.id', '=', 'o.resource_run_id')
                ->groupBy('r.external_resource_id', 'r.digital_asset_id')
                ->selectRaw('r.external_resource_id, r.digital_asset_id, count(*) as n, max(o.captured_at) as last_at')->get() as $row) {
                $kind = $row->external_resource_id !== null ? 'resource' : 'asset';
                $id = (int) ($row->external_resource_id ?? $row->digital_asset_id);
                if ($id > 0) {
                    $add($kind, $id, DataCenterCatalog::RAW, (int) $row->n, null, null, $row->last_at);
                }
            }
        }

        return $sources;
    }

    /** A date (10) or minute (16) prefix of a stored date / time value. */
    private static function stamp(mixed $value, int $length): ?string
    {
        return $value !== null && $value !== '' ? substr((string) $value, 0, $length) : null;
    }

    /**
     * @param  array<string, array{rows: int, from: ?string, through: ?string, collected_at: ?string}>  $datasets
     * @return list<array{dataset: string, label: string, rows: int, from: ?string, through: ?string, collected_at: ?string, protected: bool}>
     */
    private function datasets(array $datasets): array
    {
        $out = [];
        foreach ($datasets as $dataset => $counts) {
            $out[] = [
                'dataset' => (string) $dataset,
                'label' => $this->catalog->label((string) $dataset),
                'rows' => (int) $counts['rows'],
                'from' => $counts['from'],
                'through' => $counts['through'],
                'collected_at' => $counts['collected_at'],
                'protected' => $this->catalog->isProtected((string) $dataset),
            ];
        }
        usort($out, fn (array $a, array $b): int => [$a['protected'], $a['label']] <=> [$b['protected'], $b['label']]);

        return $out;
    }

    /**
     * The cached counts with what is read live: source name, provider, the brand it feeds, data set labels and protection.
     *
     * @param  array<string, array{key: string, kind: string, id: int, datasets: array<string, array{rows: int, from: ?string, through: ?string, collected_at: ?string}>}>  $sources
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
            $datasets = $this->datasets($source['datasets']);
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
