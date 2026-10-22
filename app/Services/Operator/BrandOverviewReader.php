<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\Measurement\Ga4Conversions;
use App\Services\Meta\MetaScreen;
use App\Services\Outcomes\Readers\MapsOutcomeReader;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Work\OpenWork;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read model of the brand page "Özet" tab (with its channel filter): period KPIs with the change against
 * the previous period of the same length, one card per digital asset with its data-source status, the open
 * suggestions and the services with their page mapping. Numbers come from the existing screen readers (Search Console
 * + GA4 via SiteAnalysisReader, Google Ads via GoogleAdsScreen, Meta via MetaScreen, İşletme Profili via the outcome
 * reader); freshness from DataStatusReader. The KPI numbers are cached per brand × period × the sources' binding, state,
 * last data day and last successful collection × today (Europe/Istanbul): they are read again when new data arrives, a
 * source is bound or re-authorized, or the day turns, not on a timer.
 */
final class BrandOverviewReader
{
    /** Period (days) => label of the Özet selector. */
    public const array PERIODS = [28 => '28 gün', 90 => '90 gün'];

    /** Longest life of the cached KPI numbers; new data, a new binding or source state, or a new day replaces them earlier. */
    public const int CACHE_MINUTES = 720;

    /** The agency's clock: "today" in the KPI cache key (the Google Ads window ends on yesterday). */
    public const string KPI_TIMEZONE = 'Europe/Istanbul';

    /** Changes whenever rawKpis() changes shape, so a value cached by an older release is never read. */
    private const string CACHE_VERSION = 'v4';

    /** Asset types shown on the brand page (infrastructure types are left out). */
    public const array TYPE_LABELS = [
        'website' => 'Web sitesi',
        'google_business_profile' => 'İşletme Profili',
        'gbp' => 'İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
        'ga4' => 'Google Analytics',
        'gsc' => 'Search Console',
        'instagram' => 'Instagram',
    ];

    /**
     * Channel tab => suggestion channel, the asset types that feed it and how the missing asset is named.
     *
     * @var array<string, array{channel: string, types: list<string>, asset_label: string}>
     */
    public const array CHANNELS = [
        'arama' => ['channel' => 'search', 'types' => ['website'], 'asset_label' => 'web sitesi'],
        'harita' => ['channel' => 'maps', 'types' => ['google_business_profile', 'gbp'], 'asset_label' => 'İşletme Profili'],
        'google_ads' => ['channel' => 'google_ads', 'types' => ['google_ads'], 'asset_label' => 'Google Ads hesabı'],
        'meta' => ['channel' => 'meta', 'types' => ['meta_ads'], 'asset_label' => 'Meta reklam hesabı'],
    ];

    private const array TONE_RANK = ['bad' => 3, 'warn' => 2, 'muted' => 1, 'ok' => 0];

    public function __construct(
        private readonly DataStatusReader $statuses,
        private readonly SiteAnalysisReader $site,
        private readonly GoogleAdsScreen $googleAds,
        private readonly MetaScreen $meta,
        private readonly GbpDailyWorkspace $gbp,
        private readonly MapsOutcomeReader $maps,
        private readonly Ga4Conversions $conversions,
    ) {}

    public static function days(int $days): int
    {
        return array_key_exists($days, self::PERIODS) ? $days : 28;
    }

    /** @return Collection<int, DigitalAsset> the brand's managed assets (no domain / hosting) */
    public function assetModels(Brand $brand): Collection
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->whereNotIn('type', ['domain', 'hosting'])->orderBy('id')->get();
    }

    /**
     * One card per asset: where it opens, its data sources with state / last data / action, the overall tone.
     *
     * @param  Collection<int, DigitalAsset>  $models
     * @return list<array{id: int, name: string, type: string, type_label: string, active: bool, url: string, sources_url: string, tone: string, summary: string, sources: list<array<string, mixed>>}>
     */
    public function assetCards(Collection $models): array
    {
        $statuses = $this->statuses->forAssets($models);
        $cards = [];
        foreach ($models as $asset) {
            $type = (string) $asset->type;
            $sources = array_map(fn (DataStatus $status): array => $this->source($status), $statuses[(int) $asset->id] ?? []);
            $tone = collect($sources)->sortByDesc(fn (array $s): int => self::TONE_RANK[$s['tone']] ?? 0)->first()['tone'] ?? 'muted';
            $cards[] = [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'type' => $type,
                'type_label' => self::TYPE_LABELS[$type] ?? $type,
                'active' => ($asset->status?->value ?? 'active') === 'active',
                'url' => OperatorPortfolioPresenter::specialistUrl($asset),
                'sources_url' => route('operator.asset.sources', ['assetId' => $asset->id]),
                'tone' => $sources === [] ? 'muted' : $tone,
                'summary' => $this->summary($sources),
                'sources' => $sources,
            ];
        }

        return $cards;
    }

    /**
     * KPI cards of the period: organic clicks (Search Console), web sessions and conversions (GA4), ad spend and ad
     * conversions (Google Ads + Meta), İşletme Profili interactions. Each card says "veri yok" with the reason and
     * the fix when its source is not bound or has not delivered rows yet.
     *
     * @param  Collection<int, DigitalAsset>  $models
     * @param  list<array<string, mixed>>  $cards  output of assetCards()
     * @return list<array{key: string, label: string, source: string, state: string, value: ?string, delta: ?int, note: string, action: ?array{label: string, url: string}}>
     */
    public function kpis(Brand $brand, Collection $models, array $cards, int $days): array
    {
        $days = self::days($days);
        $raw = $this->cachedKpis($brand, $models, $cards, $days);

        $hasData = [];
        $bound = [];
        foreach ($cards as $card) {
            foreach ($card['sources'] as $source) {
                $bound[$source['capability']] = ($bound[$source['capability']] ?? false) || $source['state'] !== DataStatus::NOT_BOUND;
                $hasData[$source['capability']] = ($hasData[$source['capability']] ?? false) || $source['last_data_date'] !== null;
            }
        }
        $website = collect($cards)->firstWhere('type', 'website');
        $bindWeb = $website !== null
            ? ['label' => 'Bağla', 'url' => route('operator.asset.sources', ['assetId' => $website['id']])]
            : ['label' => 'Web sitesi ekle', 'url' => route('operator.asset.create', ['brandId' => $brand->id])];
        $bindAccount = ['label' => 'Hesap bağla', 'url' => route('operator.brand', ['brand' => $brand->id, 'tab' => 'varliklar'])];
        $num = static fn (float|int $v): string => number_format((float) $v, 0, ',', '.');

        $web = $raw['web'];
        $ads = $raw['ads'];
        $gbp = $raw['gbp'];

        $daily = $web['daily'] ?? [];

        return [
            $this->card('organic_clicks', 'Organik tıklama', 'Search Console', $web['gsc'], $hasData['search_console'] ?? false, $web['clicks'], $num, $days,
                'Search Console bağlı değil', $bindWeb) + ['spark' => self::spark($daily['clicks'] ?? [])],
            $this->card('sessions', 'Web oturumu', 'Google Analytics', $web['ga4'], $hasData['ga4'] ?? false, $web['sessions'], $num, $days,
                'Google Analytics bağlı değil', $bindWeb) + ['spark' => self::spark($daily['sessions'] ?? [])],
            $this->card('web_conversions', 'Web dönüşümü', 'Google Analytics', $web['ga4'], $hasData['ga4'] ?? false, $web['key_events'], $num, $days,
                'Google Analytics bağlı değil', $bindWeb) + ['spark' => self::spark($daily['key_events'] ?? [])],
            $this->spendCard($ads, $days, $bindAccount),
            $this->card('ad_conversions', 'Reklam dönüşümü', $ads['sources'] !== [] ? implode(' + ', $ads['sources']) : 'Google Ads · Meta', $ads['bound'], $ads['data'],
                $ads['conversions'], $num, $days, 'Reklam hesabı bağlı değil', $bindAccount),
            $this->card('gbp_actions', 'Profil etkileşimi', 'İşletme Profili', $gbp['bound'], $gbp['data'], $gbp['actions'], $num, $days,
                'İşletme Profili bağlı değil', $bindAccount, 'arama, yol tarifi, site tıklaması'),
        ];
    }

    /**
     * Open work of the brand in packages (OpenWork: the same type of work is one item, "Başlık / açıklama: 412 sayfa"),
     * the most expected gain first, each with where to act. `total` and the per-channel numbers count packages; `rows`
     * the open suggestions behind them.
     *
     * @return array{total: int, rows: int, by_channel: array<string, int>, by_key: array<string, int>, items: list<array{id: string, channel: string, channel_label: string, title: string, reason: string, count: int, url: ?string}>}
     */
    public function openWork(Brand $brand, ?string $channel = null, int $limit = 5): array
    {
        $work = app(OpenWork::class);
        $counts = $work->counts([(int) $brand->id])[(int) $brand->id] ?? ['packages' => 0, 'items' => 0, 'critical' => 0, 'by_channel' => []];
        $byKey = $channel === null ? $counts['by_channel'] : array_intersect_key($counts['by_channel'], [$channel => true]);
        $byChannel = [];
        foreach ($byKey as $key => $n) {
            $label = (string) (Suggestion::CHANNEL_LABELS[$key] ?? $key);
            $byChannel[$label] = ($byChannel[$label] ?? 0) + $n;
        }
        $packages = $limit > 0 && $byKey !== [] ? $work->packages((int) $brand->id, $channel)->take($limit)->values() : collect();
        $first = Suggestion::query()->whereIn('id', $packages->map(fn (array $p): int => (int) $p['sample'][0]['id'])->all() ?: [0])
            ->get(['id', 'channel', 'target_type', 'target_id', 'page_id'])->keyBy('id');
        $pageSites = Page::query()->whereIn('id', $first->pluck('page_id')->filter()->unique()->values())->pluck('website_asset_id', 'id');
        $firstOfType = $packages->isEmpty() ? collect() : DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', ['website', 'google_business_profile', 'google_ads', 'meta_ads'])
            ->orderBy('id')->get(['id', 'type'])->unique('type')->pluck('id', 'type');

        return [
            'total' => array_sum($byKey),
            'rows' => $channel === null ? $counts['items'] : (int) $work->query((int) $brand->id, $channel)->count(),
            'by_channel' => $byChannel,
            'by_key' => $byKey,
            'items' => $packages->map(function (array $p) use ($first, $pageSites, $firstOfType): array {
                $suggestion = $first->get((int) $p['sample'][0]['id']);

                return [
                    'id' => (string) $p['key'], 'channel' => $p['channel'], 'channel_label' => $p['channel_label'],
                    'title' => OpenWork::line($p), 'reason' => (string) $p['sample'][0]['reason'], 'count' => (int) $p['count'],
                    'url' => $suggestion !== null ? $this->suggestionUrl($suggestion, $pageSites, $firstOfType) : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * The brand's services with how many website pages each is mapped to (offering_pages) and the hub page (the most
     * general mapped page), so the operator sees which page answers the service.
     *
     * @param  list<array<string, mixed>>  $services  BrandWorkspaceReadService::services()
     * @return array{total: int, priority: int, mapped: int, rows: list<array{id: int, name: string, is_priority: bool, pages: int, hub: ?array{path: string, url: string, website_asset_id: int}}>}
     */
    public function services(array $services, int $limit = 6): array
    {
        $rows = array_map(fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'is_priority' => (bool) $s['is_priority'],
            'pages' => count($s['pages'] ?? []), 'hub' => $s['hub'] ?? null], $services);

        return [
            'total' => count($rows),
            'priority' => count(array_filter($rows, fn (array $r): bool => $r['is_priority'])),
            'mapped' => count(array_filter($rows, fn (array $r): bool => $r['pages'] > 0)),
            'rows' => array_slice($rows, 0, $limit),
        ];
    }

    /**
     * rawKpis() cached by data freshness: the key holds every card's sources (binding, state, last data day, last
     * successful collection) and today in Europe/Istanbul, so it changes when a collection finishes, a source is bound,
     * its access breaks or is re-authorized (an account that cannot be read counts as not bound), or the day turns. The
     * value lives CACHE_MINUTES at most and never past the next midnight of a Google Ads account's own time zone, where
     * its window moves to a new yesterday.
     *
     * @param  Collection<int, DigitalAsset>  $models
     * @param  list<array<string, mixed>>  $cards
     * @return array{web: array<string, mixed>, ads: array<string, mixed>, gbp: array<string, mixed>, until: ?int}
     */
    private function cachedKpis(Brand $brand, Collection $models, array $cards, int $days): array
    {
        $sources = collect($cards)->map(fn (array $c): array => [$c['id'], array_map(
            fn (array $s): array => [$s['binding_id'], $s['state'], $s['last_data_date'], $s['last_success_at']], $c['sources'])])->all();
        $fingerprint = md5((string) json_encode([CarbonImmutable::now(self::KPI_TIMEZONE)->toDateString(), $sources]));
        $key = 'brand:overview:kpis:'.self::CACHE_VERSION.':'.$brand->id.':'.$days.':'.$fingerprint;
        $raw = Cache::get($key);
        if (is_array($raw)) {
            return $raw;
        }
        $raw = $this->rawKpis($brand, $models, $days);
        $seconds = self::CACHE_MINUTES * 60;
        if ($raw['until'] !== null) {
            $seconds = min($seconds, $raw['until'] - CarbonImmutable::now()->getTimestamp());
        }
        Cache::put($key, $raw, max(0, $seconds));

        return $raw;
    }

    /**
     * @param  Collection<int, DigitalAsset>  $models
     * @return array{web: array<string, mixed>, ads: array<string, mixed>, gbp: array<string, mixed>, until: ?int}
     */
    private function rawKpis(Brand $brand, Collection $models, int $days): array
    {
        $web = ['gsc' => false, 'ga4' => false, 'clicks' => [0, 0], 'sessions' => [0, 0], 'key_events' => [0.0, 0.0], 'daily' => ['clicks' => [], 'sessions' => [], 'key_events' => []]];
        foreach ($models->where('type', 'website') as $site) {
            $window = $this->site->window($site, $days);
            if ($window['gsc'] === [] && $window['ga4'] === []) {
                continue;
            }
            $web['gsc'] = $web['gsc'] || $window['gsc'] !== [];
            $web['ga4'] = $web['ga4'] || $window['ga4'] !== [];
            $this->addWindow($brand, $web, $window);
        }

        $ads = ['bound' => false, 'data' => false, 'sources' => [], 'spend' => [], 'conversions' => [0.0, 0.0]];
        $until = null;
        foreach ($this->googleAdsOverviews($models->where('type', 'google_ads')->values(), $days) as $overview) {
            if ($overview === null) {
                continue;
            }
            $midnight = CarbonImmutable::now($overview['timezone'])->addDay()->startOfDay()->getTimestamp();
            $until = $until === null ? $midnight : min($until, $midnight);
            $ads['bound'] = true;
            $ads['sources']['google_ads'] = 'Google Ads';
            if ($overview['current']['cost'] === null && $overview['previous']['cost'] === null) {
                continue;
            }
            $ads['data'] = true;
            $currency = (string) ($overview['currency'] ?: 'TRY');
            $ads['spend'][$currency][0] = ($ads['spend'][$currency][0] ?? 0.0) + (float) ($overview['current']['cost'] ?? 0);
            $ads['spend'][$currency][1] = ($ads['spend'][$currency][1] ?? 0.0) + (float) ($overview['previous']['cost'] ?? 0);
            $ads['conversions'][0] += (float) ($overview['current']['conversions'] ?? 0);
            $ads['conversions'][1] += (float) ($overview['previous']['conversions'] ?? 0);
        }
        $accounts = $this->metaAccounts($models->where('type', 'meta_ads')->values());
        $metaTotals = $accounts === [] ? [] : $this->meta->kpiTotals($accounts, $days);
        foreach ($accounts as $assetId => $account) {
            $ads['bound'] = true;
            $ads['sources']['meta'] = 'Meta';
            if (! isset($metaTotals[$assetId])) {
                continue;
            }
            $ads['data'] = true;
            $currency = (string) $account['currency'];
            $now = $metaTotals[$assetId]['current'];
            $before = $metaTotals[$assetId]['previous'];
            $ads['spend'][$currency][0] = ($ads['spend'][$currency][0] ?? 0.0) + (float) $now['spend'];
            $ads['spend'][$currency][1] = ($ads['spend'][$currency][1] ?? 0.0) + (float) $before['spend'];
            $ads['conversions'][0] += (float) $now['results'];
            $ads['conversions'][1] += (float) $before['results'];
        }
        $ads['sources'] = array_values($ads['sources']);

        return ['web' => $web, 'ads' => $ads, 'gbp' => $this->gbpKpis($models, $days), 'until' => $until];
    }

    /**
     * One website's numbers for both periods and per day of the current one (the sparklines), read from the same facts
     * as SiteAnalysisReader::freshTotals() (Search Console web clicks, GA4 landing sessions / key events), one query
     * per source grouped by day over both windows instead of one per source and period.
     *
     * @param  array<string, mixed>  $web
     * @param  array{start: string, end: string, prev_start: string, prev_end: string, gsc: list<int>, ga4: list<int>}  $window
     */
    private function addWindow(Brand $brand, array &$web, array $window): void
    {
        $period = static fn (string $day): ?int => $day >= $window['start'] && $day <= $window['end'] ? 0 : ($day >= $window['prev_start'] && $day <= $window['prev_end'] ? 1 : null);
        $from = min($window['start'], $window['prev_start']);
        $to = max($window['end'], $window['prev_end']);
        if ($window['gsc'] !== []) {
            $rows = DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $window['gsc'])->where('search_type', 'web')
                ->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date')
                ->selectRaw('reporting_date as day, sum(clicks) as clicks')->get();
            foreach ($rows as $row) {
                $day = substr((string) $row->day, 0, 10);
                if (($index = $period($day)) === null) {
                    continue;
                }
                $web['clicks'][$index] += (int) $row->clicks;
                if ($index === 0) {
                    $web['daily']['clicks'][$day] = ($web['daily']['clicks'][$day] ?? 0) + (int) $row->clicks;
                }
            }
        }
        if ($window['ga4'] !== []) {
            $rows = DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $window['ga4'])
                ->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date')
                ->selectRaw('reporting_date as day, sum(sessions) as sessions')->get();
            foreach ($rows as $row) {
                $day = substr((string) $row->day, 0, 10);
                if (($index = $period($day)) === null) {
                    continue;
                }
                $web['sessions'][$index] += (int) $row->sessions;
                if ($index === 0) {
                    $web['daily']['sessions'][$day] = ($web['daily']['sessions'][$day] ?? 0) + (int) $row->sessions;
                }
            }
            // Conversions as the brand dictionary counts them: GA4's keyEvents column also adds page views (audit 2).
            foreach ($this->conversions->daily($brand, $window['ga4'], $from, $to) as $day => $value) {
                if (($index = $period($day)) === null) {
                    continue;
                }
                $web['key_events'][$index] += $value;
                if ($index === 0) {
                    $web['daily']['key_events'][$day] = ($web['daily']['key_events'][$day] ?? 0.0) + $value;
                }
            }
        }
    }

    /**
     * Day => value as sparkline points in date order; a long window (90 days) is summed per week so the line stays
     * readable. Null below two points.
     *
     * @param  array<string, int|float>  $byDay
     * @return list<float>|null
     */
    public static function spark(array $byDay): ?array
    {
        if (count($byDay) < 2) {
            return null;
        }
        ksort($byDay);
        $first = CarbonImmutable::parse((string) array_key_first($byDay));
        $last = CarbonImmutable::parse((string) array_key_last($byDay));
        $points = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $points[] = (float) ($byDay[$day->toDateString()] ?? 0);
        }
        if (count($points) > 35) {
            $points = array_map(fn (array $week): float => array_sum($week), array_chunk($points, 7));
        }

        return count($points) >= 2 ? $points : null;
    }

    /**
     * Google Ads overviews of the accounts, their bindings resolved in one batch. When the batch fails (one broken
     * binding), each account is read on its own so the others still count and the broken one shows as not bound.
     *
     * @param  Collection<int, DigitalAsset>  $assets
     * @return array<int, array<string, mixed>|null> asset id => overview (null: not bound)
     */
    private function googleAdsOverviews(Collection $assets, int $days): array
    {
        if ($assets->isEmpty()) {
            return [];
        }
        try {
            return $this->googleAds->overviews($assets, $days);
        } catch (Throwable $exception) {
            report($exception);

            return $assets->mapWithKeys(fn (DigitalAsset $asset): array => [(int) $asset->id => $this->safely(fn (): ?array => $this->googleAds->overview($asset, $days))])->all();
        }
    }

    /**
     * The bound Meta ad accounts, resolved in one batch. When the batch fails (one broken binding), each asset is read
     * on its own so the others still count and the broken one shows as not bound.
     *
     * @param  Collection<int, DigitalAsset>  $assets
     * @return array<int, array<string, mixed>> asset id => account
     */
    private function metaAccounts(Collection $assets): array
    {
        if ($assets->isEmpty()) {
            return [];
        }
        try {
            return $this->meta->accounts($assets);
        } catch (Throwable $exception) {
            report($exception);

            return $assets->mapWithKeys(fn (DigitalAsset $asset): array => [(int) $asset->id => $this->safely(fn (): ?array => $this->meta->account($asset))])
                ->filter()->all();
        }
    }

    /**
     * İşletme Profili interactions of the period, each profile's window ending on its own last day. Read in batches:
     * one binding query, one last-day query, and current + previous sums once per distinct last day (profiles
     * collected together share it), not per profile.
     *
     * @param  Collection<int, DigitalAsset>  $models
     * @return array{bound: bool, data: bool, actions: array{0: int, 1: int}}
     */
    private function gbpKpis(Collection $models, int $days): array
    {
        $gbp = ['bound' => false, 'data' => false, 'actions' => [0, 0]];
        $profiles = $models->whereIn('type', ['google_business_profile', 'gbp'])->map(fn (DigitalAsset $asset): int => (int) $asset->id)->values()->all();
        $resources = $this->gbp->resourceIds($profiles);
        if ($resources === []) {
            return $gbp;
        }
        $gbp['bound'] = true;
        $lastDays = $this->maps->lastDays(array_values(array_unique($resources)));
        $byLastDay = [];
        foreach ($lastDays as $resourceId => $last) {
            $byLastDay[$last][] = $resourceId;
        }
        $current = [];
        $previous = [];
        foreach ($byLastDay as $last => $resourceIds) {
            $end = CarbonImmutable::parse((string) $last);
            $start = $end->subDays($days - 1);
            $current += $this->maps->readMany($resourceIds, $start->toDateString(), $end->toDateString());
            $previous += $this->maps->readMany($resourceIds, $start->subDays($days)->toDateString(), $start->subDay()->toDateString());
        }
        foreach ($resources as $resourceId) {
            $gbp['data'] = $gbp['data'] || isset($current[$resourceId]) || isset($previous[$resourceId]);
            $gbp['actions'][0] += (int) ($current[$resourceId]['actions'] ?? 0);
            $gbp['actions'][1] += (int) ($previous[$resourceId]['actions'] ?? 0);
        }

        return $gbp;
    }

    /**
     * @param  array{0: int|float, 1: int|float}  $values
     * @param  array{label: string, url: string}  $bindAction
     * @return array<string, mixed>
     */
    private function card(string $key, string $label, string $source, bool $bound, bool $hasData, array $values, callable $format, int $days,
        string $missing, array $bindAction, ?string $hint = null): array
    {
        if (! $bound) {
            return ['key' => $key, 'label' => $label, 'source' => $source, 'state' => 'not_bound', 'value' => null, 'delta' => null,
                'note' => $missing, 'action' => $bindAction];
        }
        if (! $hasData && (float) $values[0] === 0.0 && (float) $values[1] === 0.0) {
            return ['key' => $key, 'label' => $label, 'source' => $source, 'state' => 'no_data', 'value' => null, 'delta' => null,
                'note' => 'Bağlı · veri henüz gelmedi', 'action' => null];
        }

        return ['key' => $key, 'label' => $label, 'source' => $source, 'state' => 'ok', 'value' => $format($values[0]),
            'delta' => self::change($values[0], $values[1]),
            'note' => 'önceki '.$days.' gün: '.$format($values[1]).($hint !== null ? ' · '.$hint : ''), 'action' => null];
    }

    /**
     * @param  array<string, mixed>  $ads
     * @param  array{label: string, url: string}  $bindAction
     * @return array<string, mixed>
     */
    private function spendCard(array $ads, int $days, array $bindAction): array
    {
        $source = $ads['sources'] !== [] ? implode(' + ', $ads['sources']) : 'Google Ads · Meta';
        if (count($ads['spend']) <= 1) {
            $currency = (string) (array_key_first($ads['spend']) ?? 'TRY');
            $values = $ads['spend'][$currency] ?? [0.0, 0.0];

            return $this->card('ad_spend', 'Reklam harcaması', $source, $ads['bound'], $ads['data'], $values,
                fn (float|int $v): string => self::money((float) $v, $currency), $days, 'Reklam hesabı bağlı değil', $bindAction);
        }
        // Several currencies are never added up: each is shown on its own, without a combined change.
        $value = collect($ads['spend'])->map(fn (array $v, string $currency): string => self::money($v[0], $currency))->implode(' + ');
        $previous = collect($ads['spend'])->map(fn (array $v, string $currency): string => self::money($v[1], $currency))->implode(' + ');

        return ['key' => 'ad_spend', 'label' => 'Reklam harcaması', 'source' => $source, 'state' => 'ok', 'value' => $value, 'delta' => null,
            'note' => 'önceki '.$days.' gün: '.$previous.' · para birimleri toplanmaz', 'action' => null];
    }

    public static function money(float $value, string $currency): string
    {
        $amount = number_format($value, 0, ',', '.');

        return match (strtoupper($currency)) {
            'TRY' => '₺'.$amount,
            'USD' => '$'.$amount,
            'EUR' => '€'.$amount,
            default => $amount.' '.strtoupper($currency),
        };
    }

    public static function change(float|int $current, float|int $previous): ?int
    {
        return (float) $previous > 0 ? (int) round(((float) $current - (float) $previous) / (float) $previous * 100) : null;
    }

    /** @return array<string, mixed> */
    private function source(DataStatus $status): array
    {
        return [
            'capability' => $status->capability,
            'binding_id' => $status->bindingId,
            'label' => $status->sourceLabel(),
            'resource' => $status->resourceName,
            'state' => $status->state,
            'state_label' => $status->label(),
            'detail' => $status->detail(),
            'tone' => $status->tone(),
            'collecting' => $status->collecting,
            'last_data_date' => $status->lastDataDate?->toDateString(),
            'last_success_at' => $status->lastSuccessAt?->toIso8601String(),
            'action' => $status->action,
            'action_label' => $status->actionLabel(),
            'action_url' => $status->actionUrl,
        ];
    }

    /** @param  list<array<string, mixed>>  $sources */
    private function summary(array $sources): string
    {
        if ($sources === []) {
            return 'Veri kaynağı bağlanmaz';
        }
        $problem = collect($sources)->firstWhere('tone', 'bad') ?? collect($sources)->firstWhere('tone', 'warn') ?? collect($sources)->firstWhere('state', DataStatus::NOT_BOUND);

        return $problem !== null ? $problem['label'].': '.$problem['state_label'] : 'Veriler güncel';
    }

    /**
     * @param  Collection<int|string, mixed>  $pageSites  page id => website asset id
     * @param  Collection<string, mixed>  $firstOfType  asset type => first asset id of the brand
     */
    private function suggestionUrl(Suggestion $suggestion, Collection $pageSites, Collection $firstOfType): ?string
    {
        $target = $suggestion->target_id !== null ? (int) $suggestion->target_id : null;

        return match (true) {
            $suggestion->target_type === 'gbp' && $target !== null => route('operator.gbp', ['assetId' => $target]),
            $suggestion->target_type === 'google_ads' && $target !== null => route('operator.google-ads.overview', ['assetId' => $target]),
            $suggestion->target_type === 'meta' && $target !== null => route('operator.meta.overview', ['assetId' => $target]),
            $suggestion->target_type === 'site' && $target !== null => route('operator.website', ['assetId' => $target]),
            $suggestion->page_id !== null && isset($pageSites[$suggestion->page_id]) => route('operator.website', ['assetId' => (int) $pageSites[$suggestion->page_id]]),
            $suggestion->channel === 'search' && isset($firstOfType['website']) => route('operator.website', ['assetId' => (int) $firstOfType['website']]),
            $suggestion->channel === 'maps' && isset($firstOfType['google_business_profile']) => route('operator.gbp', ['assetId' => (int) $firstOfType['google_business_profile']]),
            $suggestion->channel === 'google_ads' && isset($firstOfType['google_ads']) => route('operator.google-ads.overview', ['assetId' => (int) $firstOfType['google_ads']]),
            $suggestion->channel === 'meta' && isset($firstOfType['meta_ads']) => route('operator.meta.overview', ['assetId' => (int) $firstOfType['meta_ads']]),
            default => null,
        };
    }

    /**
     * A broken provider binding must not take the whole brand page down; it shows as "bağlı değil" instead.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
