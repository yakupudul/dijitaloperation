<?php

namespace App\Services\Brand;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\BrandSetup\BrandSetupAreaSuggester;
use App\Services\Catalog\BrandCommercialContextService;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Repair\RepairDesk;
use App\Services\Site\SiteScope;
use App\Support\Options\LocationOptions;
use App\Support\SchemaState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Marka verisi denetimi (yakup, 2026-10-09: "alakasız hizmet lokasyonları görünüyor … bana onaylamak kalsın"). Every night
 * the brand's places and services are checked against its own evidence, by rules (no AI), and what should change waits
 * on the Onarım masası as `brand_data` rows for (bulk) approval:
 *  - a place with no evidence (no Business Profile there, no page of the site naming it, (almost) no search for it) → kaldır;
 *  - a "şube" with no Business Profile at that address while the brand's profiles have addresses → hizmet bölgesi yap;
 *  - a Business Profile address that is not a place yet → şube olarak ekle;
 *  - a district / city the site's own pages keep naming (≥3 pages and ≥10% of them) → hizmet bölgesi olarak ekle;
 *  - an unlocked, non-★ service with no page, no Ads campaign and no search naming it → arşivle.
 * Nothing is removed without enough data (≥10 pages read); a removed place is archived, never deleted, and is not
 * proposed again. A row the operator rejected stays rejected. Claude marka denetimi (BrandDataReview) stores its own
 * evidenced rows here too (renames, ★, conversion counting); each source keeps and closes only its own rows.
 */
final class BrandDataAudit
{
    public const string TYPE = 'brand_data';

    public const string REMOVE_AREA = 'remove_area';

    public const string MAKE_SERVICE_AREA = 'make_service_area';

    public const string MAKE_BRANCH = 'make_branch';

    public const string ADD_AREA = 'add_area';

    public const string ARCHIVE_SERVICE = 'archive_service';

    /** Claude marka denetimi only (BrandDataReview): a service's own name, the ★ and whether a conversion signal counts. */
    public const string RENAME_SERVICE = 'rename_service';

    public const string SET_MAIN = 'set_main';

    public const string CONVERSION_COUNTS = 'conversion_counts';

    /** Who proposed a row: the nightly rules or Claude; each source closes only its own rows. */
    public const string SOURCE_RULES = 'rules';

    public const string SOURCE_CLAUDE = 'claude';

    public const int MIN_PAGES = 10;

    public const int ADD_MIN_PAGES = 3;

    public const float ADD_MIN_SHARE = 0.1;

    /** Search Console impressions (90 days) naming a place that still count as interest. */
    public const int MIN_IMPRESSIONS = 30;

    public const int MAX_ADDS = 3;

    public function __construct(private readonly BrandCommercialContextService $context) {}

    /** @return array{brands: int, proposed: int, closed: int} */
    public function run(?int $brandId = null): array
    {
        $out = ['brands' => 0, 'proposed' => 0, 'closed' => 0];
        Brand::query()->operational()->when($brandId !== null, fn ($q) => $q->whereKey($brandId))->orderBy('id')
            ->each(function (Brand $brand) use (&$out): void {
                try {
                    [$proposed, $closed] = $this->store($brand, $this->items($brand));
                    $out['brands']++;
                    $out['proposed'] += $proposed;
                    $out['closed'] += $closed;
                } catch (Throwable $error) {
                    report($error);
                }
            });

        return $out;
    }

    /**
     * What should change in the brand's places and services, with the evidence.
     *
     * @return list<array{key: string, op: string, title: string, reason: string, priority: int, risk: string, before: string, after: string, params: array<string, mixed>}>
     */
    public function items(Brand $brand): array
    {
        $pages = $this->pages($brand);
        $queries = $this->queries($brand);
        $profiles = $this->profiles($brand);
        $mentions = $this->mentions($pages, $queries, $brand, $profiles);
        $areas = SiteScope::areas($brand);
        $archived = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', '!=', 'active')->get(['city_name', 'district_name'])
            ->map(fn (BrandServiceArea $a): string => self::key((string) $a->city_name, $a->district_name))->flip();
        $enough = count($pages) >= self::MIN_PAGES;
        $items = [];

        foreach ($areas as $area) {
            $atProfile = collect($profiles)->first(fn (array $p): bool => self::covers($area, $p['city'], $p['district'], true));
            [$pageCount, $impressions] = $this->evidence($area, $mentions);
            $label = self::place($area).($area->physical_branch ? ' (şube)' : '');
            if ($atProfile === null && $pageCount === 0 && $impressions < self::MIN_IMPRESSIONS && $enough && $areas->count() > 1) {
                $items[] = $this->item('area:'.$area->id, self::REMOVE_AREA, $label.' kaldırılsın',
                    'Bu yerde İşletme Profili yok, sitenin '.count($pages).' sayfasından hiçbiri burayı anmıyor, Search Console\'da son 90 günde '
                    .$impressions.' gösterim var.', 2, RepairDesk::MEDIUM, $label, 'Kaldırılır (arşive alınır)', ['area_id' => (int) $area->id]);

                continue;
            }
            if ($area->physical_branch && $atProfile === null && $profiles !== []) {
                $items[] = $this->item('branch:'.$area->id, self::MAKE_SERVICE_AREA, self::place($area).' şube değil, hizmet bölgesi',
                    'Markanın İşletme Profillerinin hiçbiri bu adreste değil ('.implode(', ', array_unique(array_map(fn (array $p): string => $p['label'], $profiles)))
                    .'); sayfa: '.$pageCount.', gösterim: '.$impressions.'.', 3, RepairDesk::LOW, $label, self::place($area).' (hizmet bölgesi)', ['area_id' => (int) $area->id]);
            }
        }

        foreach ($profiles as $profile) {
            $key = self::key($profile['city'], $profile['district']);
            if (isset($archived[$key]) || $areas->contains(fn (BrandServiceArea $a): bool => $a->physical_branch && self::covers($a, $profile['city'], $profile['district'], true))) {
                continue;
            }
            $plain = $areas->first(fn (BrandServiceArea $a): bool => ! $a->physical_branch && self::same($a, $profile['city'], $profile['district']));
            $place = implode(', ', array_filter([$profile['district'], $profile['city']]));
            $items[] = $plain !== null
                ? $this->item('profile:'.$key, self::MAKE_BRANCH, $place.' şube olarak işaretlensin', 'İşletme Profili bu adreste: '.$profile['name'].'.', 2,
                    RepairDesk::LOW, self::place($plain).' (hizmet bölgesi)', $place.' (şube)', ['area_id' => (int) $plain->id])
                : $this->item('profile:'.$key, self::ADD_AREA, $place.' şube olarak eklensin', 'İşletme Profili bu adreste: '.$profile['name'].'; markanın yerlerinde yok.', 2,
                    RepairDesk::LOW, '—', $place.' (şube)', ['city' => $profile['city'], 'district' => $profile['district'], 'physical' => true]);
        }

        $adds = 0;
        foreach (collect($mentions)->sortByDesc('pages') as $key => $mention) {
            if ($adds >= self::MAX_ADDS || $mention['pages'] < self::ADD_MIN_PAGES || $mention['pages'] < count($pages) * self::ADD_MIN_SHARE
                || isset($archived[$key]) || $areas->contains(fn (BrandServiceArea $a): bool => self::covers($a, $mention['city'], $mention['district'], false))
                || collect($profiles)->contains(fn (array $p): bool => self::key($p['city'], $p['district']) === $key)
                || ($mention['district'] === null && ($areas->contains(fn (BrandServiceArea $a): bool => LocationOptions::fold((string) $a->city_name) === LocationOptions::fold($mention['city']))
                    || collect($profiles)->contains(fn (array $p): bool => LocationOptions::fold($p['city']) === LocationOptions::fold($mention['city']))))) {
                // A province the brand already works in (through a district or a profile) is not a new place.
                continue;
            }
            $place = implode(', ', array_filter([$mention['district'], $mention['city']]));
            $items[] = $this->item('page:'.$key, self::ADD_AREA, $place.' hizmet bölgesi olarak eklensin',
                'Sitenin '.count($pages).' sayfasından '.$mention['pages'].' tanesi burayı anıyor (ör. '.implode(', ', array_slice($mention['samples'], 0, 2)).')'
                .($mention['impressions'] > 0 ? '; Search Console\'da '.$mention['impressions'].' gösterim.' : '.'), 3,
                RepairDesk::MEDIUM, '—', $place.' (hizmet bölgesi)', ['city' => $mention['city'], 'district' => $mention['district'], 'physical' => false]);
            $adds++;
        }

        if ($enough) {
            foreach ($this->unevidencedServices($brand, $pages, $queries) as $offering) {
                $items[] = $this->item('service:'.$offering->id, self::ARCHIVE_SERVICE, $offering->displayName().' hizmeti arşivlensin',
                    'Sitenin '.count($pages).' sayfasından hiçbiri bu hizmeti anmıyor, hiçbir sayfa ya da reklam kampanyası ona bağlı değil, Search Console\'da onu arayan sorgu yok.',
                    3, RepairDesk::MEDIUM, $offering->displayName(), 'Arşive alınır (Marka › Ayarlar\'dan geri açılabilir)', ['offering_id' => (int) $offering->id]);
            }
        }

        return $items;
    }

    /**
     * The evidence behind the brand's services and places, for Claude marka denetimi (BrandDataReview): the same pages,
     * searches and profile addresses the rules read.
     *
     * @return array{pages_read: int, services: list<array<string, mixed>>, areas: list<array<string, mixed>>, profiles: list<array{city: string, district: ?string, name: string}>, place_mentions: list<array{city: string, district: ?string, pages: int, impressions: int}>}
     */
    public function reviewFacts(Brand $brand): array
    {
        $pages = $this->pages($brand);
        $queries = $this->queries($brand);
        $profiles = $this->profiles($brand);
        $mentions = $this->mentions($pages, $queries, $brand, $profiles);
        $folded = array_map(fn (array $q): array => [' '.LocationOptions::fold($q['query']).' ', $q['impressions']], $queries);
        $services = BrandOffering::query()->with(['names', 'primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active->value)->orderBy('id')->limit(60)->get();
        $pageCounts = OfferingPage::query()->whereIn('brand_offering_id', $services->pluck('id')->all() ?: [0])->groupBy('brand_offering_id')
            ->selectRaw('brand_offering_id, count(*) as n')->pluck('n', 'brand_offering_id')->all();
        $services = $services->map(function (BrandOffering $o) use ($folded, $pageCounts): array {
            $names = $o->names->where('is_active', true)->pluck('raw_label')->push($o->catalogItem?->primaryName?->raw_label)->filter()->map(fn ($n): string => trim((string) $n))->unique();
            $keys = $names->map(fn (string $n): string => LocationOptions::fold($n))->filter(fn (string $n): bool => mb_strlen($n) >= 3)->unique()->all();
            $hits = array_filter($folded, fn (array $q): bool => collect($keys)->contains(fn (string $k): bool => str_contains($q[0], $k)));

            return ['id' => (int) $o->id, 'name' => $o->displayName(), 'aliases' => $names->reject(fn (string $n): bool => $n === $o->displayName())->values()->take(8)->all(),
                'main' => $o->isMain(), 'locked' => (bool) $o->locked, 'own_name' => $o->primaryName !== null, 'pages' => (int) ($pageCounts[$o->id] ?? 0),
                'queries' => count($hits), 'impressions' => (int) array_sum(array_column($hits, 1))];
        })->values()->all();
        $areas = SiteScope::areas($brand)->map(function (BrandServiceArea $area) use ($mentions, $profiles): array {
            [$pageCount, $impressions] = $this->evidence($area, $mentions);

            return ['id' => (int) $area->id, 'name' => self::place($area), 'city' => (string) $area->city_name, 'district' => $area->district_name,
                'branch' => (bool) $area->physical_branch, 'pages' => $pageCount, 'impressions' => $impressions,
                'at_profile' => collect($profiles)->contains(fn (array $p): bool => self::covers($area, $p['city'], $p['district'], true))];
        })->values()->all();

        return [
            'pages_read' => count($pages),
            'services' => $services,
            'areas' => $areas,
            'profiles' => array_map(fn (array $p): array => ['city' => $p['city'], 'district' => $p['district'], 'name' => $p['name']], $profiles),
            'place_mentions' => collect($mentions)->sortByDesc(fn (array $m): int => $m['pages'] * 100 + $m['impressions'])->take(15)
                ->map(fn (array $m): array => ['city' => $m['city'], 'district' => $m['district'], 'pages' => $m['pages'], 'impressions' => $m['impressions']])->values()->all(),
        ];
    }

    /** The key the rules compare places by (folded il | ilçe). */
    public static function placeKey(string $city, ?string $district): string
    {
        return self::key($city, $district);
    }

    /** Approve: applies one row inside MoxDOP (no external write). */
    public function apply(User $user, Suggestion $suggestion): void
    {
        $action = (array) $suggestion->action;
        $params = (array) ($action['params'] ?? []);
        $brand = Brand::query()->findOrFail((int) $suggestion->brand_id);
        $area = fn (): BrandServiceArea => BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->findOrFail((int) ($params['area_id'] ?? 0));
        $offering = fn (): BrandOffering => BrandOffering::query()->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value)->findOrFail((int) ($params['offering_id'] ?? 0));
        DB::transaction(function () use ($action, $params, $brand, $area, $offering, $user): void {
            match ((string) ($action['op'] ?? '')) {
                // The old name stays on the service as its former name, so pages and searches still match it.
                self::RENAME_SERVICE => app(BrandOfferingService::class)->renameLocal($offering(), (string) ($params['new_name'] ?? ''), $user),
                self::SET_MAIN => $this->setMain($brand, $offering()),
                self::CONVERSION_COUNTS => $this->setCounts($brand, $params),
                self::REMOVE_AREA => $area()->forceFill(['status' => 'archived'])->save(),
                self::MAKE_SERVICE_AREA => $area()->forceFill(['physical_branch' => false])->save(),
                self::MAKE_BRANCH => $area()->forceFill(['physical_branch' => true])->save(),
                self::ADD_AREA => $this->context->addServiceArea($brand, ['country_code' => 'TR', 'city_name' => (string) $params['city'], 'district_name' => (string) ($params['district'] ?? '')])
                    ->forceFill(['physical_branch' => (bool) ($params['physical'] ?? false)])->save(),
                self::ARCHIVE_SERVICE => BrandOffering::query()->where('brand_id', $brand->id)->where('locked', false)->findOrFail((int) ($params['offering_id'] ?? 0))
                    ->forceFill(['status' => OfferingStatus::Archived->value])->save(),
                default => throw ValidationException::withMessages(['brand_data' => 'Bilinmeyen marka verisi değişikliği.']),
            };
        });
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_at' => now(), 'resolved_by' => $user->id,
            'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $params */
    private function setCounts(Brand $brand, array $params): void
    {
        $row = BrandConversionSource::query()->where('brand_id', $brand->id)->findOrFail((int) ($params['conversion_id'] ?? 0));
        app(BrandConversionDictionary::class)->set($row, (string) $row->conversion_type, (bool) ($params['counts'] ?? false));
    }

    /** The approved service becomes the only ★. */
    private function setMain(Brand $brand, BrandOffering $main): void
    {
        BrandOffering::query()->where('brand_id', $brand->id)->whereKeyNot($main->id)
            ->where(fn ($q) => $q->where('priority', 'main')->orWhere('is_priority', true))->get()
            ->each(fn (BrandOffering $o) => $o->forceFill(['priority' => 'secondary', 'is_priority' => false])->save());
        $main->forceFill(['priority' => 'main', 'is_priority' => true])->save();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{key: string, op: string, title: string, reason: string, priority: int, risk: string, before: string, after: string, params: array<string, mixed>}
     */
    private function item(string $key, string $op, string $title, string $reason, int $priority, string $risk, string $before, string $after, array $params): array
    {
        return compact('key', 'op', 'title', 'reason', 'priority', 'risk', 'before', 'after', 'params');
    }

    /**
     * Upserts the brand's rows of one source (the nightly rules, or Claude marka denetimi); open rows of that source no
     * longer proposed are closed. Rejected and applied rows are not reopened. A Claude row the rules already propose
     * (same change, same target) is not added twice.
     *
     * @param  list<array<string, mixed>>  $items  item() rows; Claude rows also carry `evidence` (list<string>)
     * @return array{0: int, 1: int}
     */
    public function store(Brand $brand, array $items, string $source = self::SOURCE_RULES): array
    {
        $all = Suggestion::query()->where('brand_id', $brand->id)->where('action_type', self::TYPE)->get();
        $existing = $all->filter(fn (Suggestion $s): bool => (((array) $s->action)['source'] ?? self::SOURCE_RULES) === $source)->keyBy('fingerprint');
        $ruleKeys = $source === self::SOURCE_RULES ? [] : $all->filter(fn (Suggestion $s): bool => (((array) $s->action)['source'] ?? self::SOURCE_RULES) === self::SOURCE_RULES
            && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::APPROVED], true))->pluck('fingerprint')->flip()->all();
        $label = $source === self::SOURCE_CLAUDE ? 'Claude marka denetimi' : 'Marka verisi denetimi';
        $seen = [];
        $opened = 0;
        foreach ($items as $item) {
            if (isset($ruleKeys[hash('sha256', $brand->id.'|brand-data|'.$item['op'].'|'.$item['key'])])) {
                continue;
            }
            $fingerprint = hash('sha256', $brand->id.'|brand-data|'.($source === self::SOURCE_RULES ? '' : $source.'|').$item['op'].'|'.$item['key']);
            $seen[] = $fingerprint;
            $evidence = array_values(array_filter(array_map(fn ($line): string => mb_substr(trim((string) $line), 0, 400), (array) ($item['evidence'] ?? [$item['reason']]))));
            $values = ['channel' => 'search', 'decision_key' => 'repair.'.self::TYPE, 'title' => mb_substr($item['title'], 0, 160),
                'reason' => mb_substr($item['reason'], 0, 240), 'priority' => $item['priority'], 'action_type' => self::TYPE,
                'target_type' => 'brand', 'target_id' => null, 'last_seen_at' => now(),
                'material_hash' => hash('sha256', $item['op'].'|'.$item['key']),
                'evidence' => array_map(fn (string $line): array => ['kind' => 'quote', 'value' => $line, 'source' => $label], $evidence),
                'action' => ['op' => $item['op'], 'risk' => $item['risk'], 'before' => $item['before'], 'after' => $item['after'], 'params' => $item['params']]
                    + ($source === self::SOURCE_RULES ? [] : ['source' => $source])];
            $row = $existing->get($fingerprint);
            if ($row === null) {
                Suggestion::openOnce($values + ['brand_id' => $brand->id, 'fingerprint' => $fingerprint, 'status' => Suggestion::OPEN, 'first_seen_at' => now()]);
                $opened++;
            } elseif (in_array($row->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
                $row->forceFill($values)->save();
            }
        }
        $closed = $existing->filter(fn (Suggestion $s): bool => ! in_array($s->fingerprint, $seen, true) && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK], true))
            ->each(fn (Suggestion $s) => $s->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => 'Kanıt değişti, artık gerekmiyor (otomatik kapandı).'])->save())
            ->count();

        return [$opened, $closed];
    }

    /**
     * Read pages of the brand's sites as "title + path words".
     *
     * @return list<array{url: string, text: string}>
     */
    private function pages(Brand $brand): array
    {
        $sites = DigitalAsset::query()->operational()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $sites->all() ?: [0])->orderBy('id')->limit(3000)->get(['url', 'title'])
            ->map(fn (Page $p): array => ['url' => (string) $p->url, 'text' => trim((string) $p->title.' '.str_replace(['-', '_', '/'], ' ', rawurldecode((string) parse_url((string) $p->url, PHP_URL_PATH))))])
            ->all();
    }

    /** @return list<array{query: string, impressions: int}> non-branded Search Console queries of the last 90 days */
    private function queries(Brand $brand): array
    {
        $ids = SiteScope::resourceIds($brand, 'search_console');
        if ($ids === [] || ! SchemaState::hasTable('gsc_query_page_daily')) {
            return [];
        }

        return DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $ids)->where('reporting_date', '>=', now()->subDays(90)->toDateString())
            ->selectRaw('query, sum(impressions) as impressions')->groupBy('query')->orderByDesc('impressions')->limit(2000)->get()
            ->map(fn (object $r): array => ['query' => (string) $r->query, 'impressions' => (int) $r->impressions])->all();
    }

    /** @return list<array{city: string, district: ?string, name: string, label: string}> addresses of the brand's bound Business Profiles */
    private function profiles(Brand $brand): array
    {
        $out = [];
        foreach (CoreExternalResource::query()->whereIn('id', BrandScope::resources((int) $brand->id, 'google_business_profile') ?: [0])->get() as $resource) {
            if (($address = BrandSetupAreaSuggester::address($resource)) === null) {
                continue;
            }
            try {
                $area = LocationOptions::normalizeArea('TR', $address[0], $address[1]);
            } catch (Throwable) {
                continue;
            }
            $out[] = ['city' => (string) $area['city_name'], 'district' => $area['district_name'], 'name' => $address[2],
                'label' => implode(', ', array_filter([$area['district_name'], $area['city_name']]))];
        }

        return $out;
    }

    /**
     * Places named by the site's pages and searches, resolved to (il, ilçe). A district name that exists in several
     * provinces is kept only when one of them is a province the brand already knows (its places, profiles or one the
     * pages name).
     *
     * @param  list<array{url: string, text: string}>  $pages
     * @param  list<array{query: string, impressions: int}>  $queries
     * @param  list<array{city: string, district: ?string}>  $profiles
     * @return array<string, array{city: string, district: ?string, pages: int, impressions: int, samples: list<string>}>
     */
    private function mentions(array $pages, array $queries, Brand $brand, array $profiles): array
    {
        $pageNames = array_map(fn (array $p): array => LocationOptions::strip($p['text'])['removed'], $pages);
        $queryNames = array_map(fn (array $q): array => LocationOptions::strip($q['query'])['removed'], $queries);
        $cities = SiteScope::areas($brand)->map(fn (BrandServiceArea $a): string => LocationOptions::fold((string) $a->city_name))->all();
        foreach ($profiles as $p) {
            $cities[] = LocationOptions::fold($p['city']);
        }
        foreach ($pageNames as $names) {
            foreach ($names as $name) {
                foreach (LocationOptions::describe($name) as $reading) {
                    if ($reading['kind'] === 'city') {
                        $cities[] = LocationOptions::fold((string) $reading['city']);
                    }
                }
            }
        }
        $cities = array_flip($cities);
        $resolve = function (string $name) use ($cities): ?array {
            $readings = array_values(array_filter(LocationOptions::describe($name), fn (array $r): bool => $r['country_code'] === 'TR' && $r['city'] !== null && $r['kind'] !== 'country'));
            if (($city = collect($readings)->firstWhere('kind', 'city')) !== null) {
                return [$city['city'], null];
            }
            $known = array_values(array_filter($readings, fn (array $r): bool => isset($cities[LocationOptions::fold((string) $r['city'])])));

            if (count($readings) === 1) {
                return [$readings[0]['city'], $readings[0]['district']];
            }

            return count($known) === 1 ? [$known[0]['city'], $known[0]['district']] : null;
        };
        $out = [];
        $add = function (string $name, int $pages, int $impressions, ?string $sample) use (&$out, $resolve): void {
            if (($place = $resolve($name)) === null) {
                return;
            }
            $key = self::key($place[0], $place[1]);
            $out[$key] ??= ['city' => $place[0], 'district' => $place[1], 'pages' => 0, 'impressions' => 0, 'samples' => []];
            $out[$key]['pages'] += $pages;
            $out[$key]['impressions'] += $impressions;
            if ($sample !== null && count($out[$key]['samples']) < 3) {
                $out[$key]['samples'][] = $sample;
            }
        };
        foreach ($pageNames as $i => $names) {
            foreach (array_unique($names) as $name) {
                $add($name, 1, 0, (string) parse_url($pages[$i]['url'], PHP_URL_PATH));
            }
        }
        foreach ($queryNames as $i => $names) {
            foreach (array_unique($names) as $name) {
                $add($name, 0, $queries[$i]['impressions'], null);
            }
        }

        return $out;
    }

    /**
     * Pages and impressions naming the area (a province-wide area counts every mention inside the province).
     *
     * @param  array<string, array{city: string, district: ?string, pages: int, impressions: int}>  $mentions
     * @return array{0: int, 1: int}
     */
    private function evidence(BrandServiceArea $area, array $mentions): array
    {
        $pages = 0;
        $impressions = 0;
        foreach ($mentions as $m) {
            if (self::covers($area, $m['city'], $m['district'], false)) {
                $pages += $m['pages'];
                $impressions += $m['impressions'];
            }
        }

        return [$pages, $impressions];
    }

    /**
     * Services with nothing behind them: unlocked, not ★, older than a week, no page or Ads campaign assigned, and no
     * page or search naming them.
     *
     * @param  list<array{url: string, text: string}>  $pages
     * @param  list<array{query: string, impressions: int}>  $queries
     * @return Collection<int, BrandOffering>
     */
    private function unevidencedServices(Brand $brand, array $pages, array $queries): Collection
    {
        $texts = ' '.implode(' | ', array_map(fn (array $p): string => LocationOptions::fold($p['text']), $pages)).' | '
            .implode(' | ', array_map(fn (array $q): string => LocationOptions::fold($q['query']), $queries)).' ';

        return BrandOffering::query()->with(['names', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value)
            ->where('locked', false)->where('created_at', '<', now()->subDays(7))->get()
            ->reject(fn (BrandOffering $o): bool => $o->isMain())
            ->reject(fn (BrandOffering $o): bool => OfferingPage::query()->where('brand_offering_id', $o->id)->exists())
            ->reject(fn (BrandOffering $o): bool => SchemaState::hasTable('ad_campaign_services') && DB::table('ad_campaign_services')->where('brand_offering_id', $o->id)->exists())
            ->reject(function (BrandOffering $o) use ($texts): bool {
                $names = $o->names->pluck('raw_label')->push($o->catalogItem?->primaryName?->raw_label)->filter()
                    ->map(fn ($n): string => LocationOptions::fold((string) $n))->filter(fn (string $n): bool => mb_strlen($n) >= 3)->unique();

                return $names->contains(fn (string $n): bool => str_contains($texts, $n));
            })->values();
    }

    /** Whether the area covers (il, ilçe); $exact for a profile address (a province-wide şube does not cover a district). */
    private static function covers(BrandServiceArea $area, string $city, ?string $district, bool $exact): bool
    {
        if (LocationOptions::fold((string) $area->city_name) !== LocationOptions::fold($city)) {
            return false;
        }
        if (blank($area->district_name)) {
            return ! $exact || blank($district) || ! $area->physical_branch;
        }

        return filled($district) && LocationOptions::fold((string) $area->district_name) === LocationOptions::fold((string) $district);
    }

    /** The operator's name of the area, else "ilçe, il". */
    private static function place(BrandServiceArea $area): string
    {
        return filled($area->name) ? (string) $area->name : implode(', ', array_filter([$area->district_name, $area->city_name]));
    }

    private static function same(BrandServiceArea $area, string $city, ?string $district): bool
    {
        return self::key((string) $area->city_name, $area->district_name) === self::key($city, $district);
    }

    private static function key(string $city, ?string $district): string
    {
        return LocationOptions::fold($city).'|'.LocationOptions::fold((string) $district);
    }
}
