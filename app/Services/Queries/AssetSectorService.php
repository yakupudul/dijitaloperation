<?php

namespace App\Services\Queries;

use App\Ai\Agents\Queries\AssetSectorAgent;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sector of every discovered digital asset — each account (Search Console, GA4, Business Profile, Google Ads, Meta)
 * and each website, bound to a brand or not. Stored in asset_sectors with its method:
 *  - manual: set by the operator; never changed again by the system;
 *  - brand:  the account belongs to a brand with exactly one sector (no AI);
 *  - ai:     assigned by the AI from the account's signals (name, web address, Business Profile category, brand,
 *            top queries), many accounts per call; re-asked only when those signals change;
 *  - none:   not known yet.
 * sectorForResource() / sectorForAsset() give the effective sector the query pipeline files queries under.
 */
final class AssetSectorService
{
    public const string RESOURCE = 'resource';

    public const string ASSET = 'asset';

    public const string MANUAL = 'manual';

    public const string AI = 'ai';

    public const string BRAND = 'brand';

    public const string NONE = 'none';

    public const array RESOURCE_TYPES = ['search_console', 'ga4', 'google_business_profile', 'google_ads', 'meta_ads'];

    /** @var array{resource: array<int, ?string>, asset: array<int, ?string>}|null */
    private ?array $map = null;

    public function __construct(private readonly BrainAi $ai) {}

    public function reset(): void
    {
        $this->map = null;
    }

    public function sectorForResource(int $resourceId): ?string
    {
        return $this->effective()['resource'][$resourceId] ?? null;
    }

    public function sectorForAsset(int $assetId): ?string
    {
        return $this->effective()['asset'][$assetId] ?? null;
    }

    /**
     * Operator choice (manual wins forever). A null category clears the manual choice back to "not known".
     */
    public function set(string $subjectType, int $subjectId, ?int $categoryId, User $actor): void
    {
        abort_unless(in_array($subjectType, [self::RESOURCE, self::ASSET], true), 422);
        if ($categoryId !== null) {
            ServiceCategory::query()->findOrFail($categoryId);
        }
        DB::table('asset_sectors')->updateOrInsert(
            ['subject_type' => $subjectType, 'subject_id' => $subjectId],
            ['service_category_id' => $categoryId, 'method' => $categoryId !== null ? self::MANUAL : self::NONE, 'confidence' => $categoryId !== null ? 1 : null,
                'reason' => null, 'signals_hash' => null, 'assigned_at' => now(), 'assigned_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()],
        );
        $this->reset();
        // The account's queries are filed again under the new sector on the next pipeline pass.
        DB::table('query_ingest_states')->where($subjectType === self::RESOURCE ? 'external_resource_id' : 'digital_asset_id', $subjectId)->update(['context_hash' => null]);
    }

    /**
     * Every discovered asset with its sector row, bound brand and signals, for the Keşfedilen varlıklar screen and the
     * AI batch.
     *
     * @return Collection<string, array{key: string, type: string, id: int, kind: string, name: string, host: ?string, brand_id: ?int, brand: ?string, customer: ?string, sector_id: ?int, method: string, confidence: ?float, reason: ?string, signals_hash: ?string, bound: bool}>
     */
    public function subjects(): Collection
    {
        $rows = DB::table('asset_sectors')->get()->keyBy(fn ($r): string => $r->subject_type.':'.$r->subject_id);
        $bindings = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with('digitalAsset.brand.customer')->get()->keyBy('external_resource_id');
        $grouper = app(PortfolioDiscoveryGrouper::class);
        $out = collect();
        CoreExternalResource::query()->whereIn('resource_type', self::RESOURCE_TYPES)->orderBy('id')->get()
            ->reject(fn (CoreExternalResource $r): bool => (bool) data_get($r->metadata, 'is_manager', false))
            ->each(function (CoreExternalResource $r) use (&$out, $rows, $bindings, $grouper): void {
                $asset = $bindings->get($r->id)?->digitalAsset;
                $row = $rows->get(self::RESOURCE.':'.$r->id);
                $out->put(self::RESOURCE.':'.$r->id, [
                    'key' => self::RESOURCE.':'.$r->id, 'type' => self::RESOURCE, 'id' => (int) $r->id, 'kind' => (string) $r->resource_type,
                    'name' => trim((string) ($r->display_name ?: $r->external_id)), 'host' => $grouper->hostOf($r),
                    'brand_id' => $asset?->brand_id !== null ? (int) $asset->brand_id : null, 'brand' => $asset?->brand?->name,
                    'customer' => $asset?->brand?->customer?->name, 'bound' => $asset !== null,
                ] + $this->rowFields($row));
            });
        DigitalAsset::query()->with('brand.customer')->where('type', 'website')->orderBy('id')->get()
            ->each(function (DigitalAsset $site) use (&$out, $rows): void {
                $row = $rows->get(self::ASSET.':'.$site->id);
                $out->put(self::ASSET.':'.$site->id, [
                    'key' => self::ASSET.':'.$site->id, 'type' => self::ASSET, 'id' => (int) $site->id, 'kind' => 'website',
                    'name' => (string) ($site->domain ?: $site->name), 'host' => BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain)) ?: null,
                    'brand_id' => $site->brand_id !== null ? (int) $site->brand_id : null, 'brand' => $site->brand?->name,
                    'customer' => $site->brand?->customer?->name, 'bound' => $site->brand_id !== null,
                ] + $this->rowFields($row));
            });

        return $out;
    }

    /**
     * Daily: brand-derived sectors without AI, then one AI call per batch for the rest that have no sector yet or
     * whose signals changed. Manual rows are never touched.
     *
     * @return array{brand: int, ai: int, unknown: int, skipped: int}
     */
    public function assignPending(int $limit = 300): array
    {
        $stats = ['brand' => 0, 'ai' => 0, 'unknown' => 0, 'skipped' => 0];
        $categories = ServiceCategory::query()->get(['id', 'code', 'name']);
        $byCode = $categories->keyBy('code');
        $brands = Brand::query()->with('sectors')->get()->keyBy('id');
        $pending = [];
        foreach ($this->subjects() as $subject) {
            if ($subject['method'] === self::MANUAL) {
                continue;
            }
            $brand = $subject['brand_id'] !== null ? $brands->get($subject['brand_id']) : null;
            $codes = $brand?->sectorCodes() ?? [];
            if (count($codes) === 1 && $byCode->has($codes[0])) {
                if ($subject['method'] !== self::BRAND || $subject['sector_id'] !== (int) $byCode[$codes[0]]->id) {
                    $this->store($subject, (int) $byCode[$codes[0]]->id, self::BRAND, 1.0, 'Markanın sektörü', null);
                    $stats['brand']++;
                }

                continue;
            }
            $signals = $this->signals($subject, $brand);
            $hash = hash('sha256', json_encode(array_diff_key($signals, ['queries' => true]) + ['has_queries' => $signals['queries'] !== []], JSON_THROW_ON_ERROR));
            if ($subject['signals_hash'] === $hash && in_array($subject['method'], [self::AI, self::NONE], true)) {
                $stats['skipped']++;

                continue;
            }
            $pending[$subject['key']] = ['subject' => $subject, 'signals' => $signals, 'hash' => $hash];
            if (count($pending) >= $limit) {
                break;
            }
        }
        if ($pending === [] || ! $this->ai->available(AiRouteKeys::QUERIES_ASSET_SECTOR)) {
            $this->reset();

            return $stats;
        }
        $sectors = $categories->map(fn ($c): array => ['code' => (string) $c->code, 'label' => (string) $c->name])->values()->all();
        foreach (array_chunk($pending, max(1, (int) config('moxdop-queries.sector_batch_size', 25)), true) as $chunk) {
            try {
                $answer = $this->ai->ask(new AssetSectorAgent, AiRouteKeys::QUERIES_ASSET_SECTOR, [
                    'sectors' => $sectors,
                    'assets' => array_values(array_map(fn (array $p): array => ['key' => $p['subject']['key']] + $p['signals'], $chunk)),
                ]);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }
            foreach ((array) ($answer['items'] ?? []) as $item) {
                $entry = $chunk[(string) ($item['key'] ?? '')] ?? null;
                if ($entry === null) {
                    continue;
                }
                $category = $byCode->get((string) ($item['sector'] ?? ''));
                $confidence = is_numeric($item['confidence'] ?? null) ? max(0.0, min(1.0, (float) $item['confidence'])) : null;
                $this->store($entry['subject'], $category?->id !== null ? (int) $category->id : null, $category !== null ? self::AI : self::NONE,
                    $confidence, mb_substr(trim((string) ($item['reason'] ?? '')), 0, 500), $entry['hash']);
                $category !== null ? $stats['ai']++ : $stats['unknown']++;
            }
        }
        $this->reset();

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array{type: string, name: string, host: ?string, category: ?string, brand: ?string, brand_sectors: list<string>, queries: list<string>}
     */
    private function signals(array $subject, ?Brand $brand): array
    {
        $category = null;
        if ($subject['kind'] === 'google_business_profile') {
            $category = DB::table('gbp_location_snapshots')->where('external_resource_id', $subject['id'])->orderByDesc('id')->value('primary_category');
        }
        $queries = DB::table('query_variants')
            ->when($subject['type'] === self::RESOURCE, fn ($q) => $q->where('external_resource_id', $subject['id']),
                fn ($q) => $q->where(fn ($w) => $w->where('digital_asset_id', $subject['id'])
                    ->orWhereIn('external_resource_id', CoreAssetBinding::query()->where('digital_asset_id', $subject['id'])->where('status', CoreAssetBinding::STATUS_ACTIVE)->select('external_resource_id'))))
            ->where('impressions', '>', 0)->orderByDesc('impressions')->limit(15)->pluck('raw_text')
            ->map(fn ($text): string => mb_substr((string) $text, 0, 100))->all();

        return [
            'type' => (string) $subject['kind'], 'name' => mb_substr((string) $subject['name'], 0, 150), 'host' => $subject['host'],
            'category' => $category !== null ? (string) $category : null, 'brand' => $brand?->name,
            'brand_sectors' => $brand?->sectorCodes() ?? [], 'queries' => $queries,
        ];
    }

    /** @param  array<string, mixed>  $subject */
    private function store(array $subject, ?int $categoryId, string $method, ?float $confidence, ?string $reason, ?string $hash): void
    {
        DB::table('asset_sectors')->updateOrInsert(
            ['subject_type' => $subject['type'], 'subject_id' => $subject['id']],
            ['service_category_id' => $categoryId, 'method' => $method, 'confidence' => $confidence, 'reason' => $reason,
                'signals_hash' => $hash, 'assigned_at' => now(), 'assigned_by' => null, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** @return array{sector_id: ?int, method: string, confidence: ?float, reason: ?string, signals_hash: ?string} */
    private function rowFields(?object $row): array
    {
        return [
            'sector_id' => $row?->service_category_id !== null ? (int) $row->service_category_id : null,
            'method' => (string) ($row->method ?? self::NONE),
            'confidence' => $row?->confidence !== null ? (float) $row->confidence : null,
            'reason' => $row->reason ?? null,
            'signals_hash' => $row->signals_hash ?? null,
        ];
    }

    /**
     * Effective sector codes: own row → (resource) the bound website's row → the brand's single sector.
     *
     * @return array{resource: array<int, ?string>, asset: array<int, ?string>}
     */
    private function effective(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $codes = ServiceCategory::query()->pluck('code', 'id')->all();
        $rows = DB::table('asset_sectors')->whereNotNull('service_category_id')->get();
        $own = ['resource' => [], 'asset' => []];
        foreach ($rows as $row) {
            $own[$row->subject_type][(int) $row->subject_id] = $codes[(int) $row->service_category_id] ?? null;
        }
        $brandSector = [];
        foreach (Brand::query()->with('sectors')->get() as $brand) {
            $list = $brand->sectorCodes();
            $brandSector[(int) $brand->id] = count($list) === 1 ? $list[0] : null;
        }
        $assetBrand = DigitalAsset::query()->whereNotNull('brand_id')->pluck('brand_id', 'id')->map('intval')->all();
        $map = ['resource' => [], 'asset' => $own['asset']];
        foreach ($assetBrand as $assetId => $brandId) {
            $map['asset'][$assetId] ??= $brandSector[$brandId] ?? null;
        }
        foreach (CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->get(['external_resource_id', 'digital_asset_id']) as $binding) {
            $map['resource'][(int) $binding->external_resource_id] = $map['asset'][(int) $binding->digital_asset_id] ?? null;
        }
        foreach ($own['resource'] as $resourceId => $code) {
            $map['resource'][$resourceId] = $code;
        }

        return $this->map = $map;
    }
}
