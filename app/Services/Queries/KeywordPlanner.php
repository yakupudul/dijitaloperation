<?php

namespace App\Services\Queries;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsRequestGovernor;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Intel\SerpResults;
use App\Services\Site\SiteScope;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Anahtar Kelime Planlayıcı (yakup, 2026-10-09 "hemen bağlayabilirsin"): search demand for brands whose own Search
 * Console / Google Ads data is thin (Burcu Kısa: 28 queries). Read only: GenerateKeywordIdeas with the brand's service
 * names (and its city) as seeds, Turkey, the site's main language. Ideas that a matching keyword of the brand's own
 * services places (and that people search at least MIN_SEARCHES a month) become raw rows of the query library
 * (`query_sources`, source `keyword_planner`, on the brand's Google Ads or Search Console account): the library
 * pipeline and the query autopilot take them like any other query (Bekleyenler → library → clusters), and from the
 * clusters the content pool. Average monthly searches and the 12-month curve are kept in `query_volumes`.
 */
final class KeywordPlanner
{
    public const string SOURCE = 'keyword_planner';

    public const int MIN_SEARCHES = 20;

    /** Seeds per call (the API takes at most 20). */
    private const int SEEDS = 20;

    /** Ideas kept per brand and run. */
    private const int KEEP = 300;

    /** A brand is asked again after this many days. */
    public const int REFRESH_DAYS = 30;

    /** Google Ads location: Turkey. */
    private const int TURKEY = 2792;

    /** Site language → Google Ads language constant. */
    private const array LANGUAGES = ['tr' => 1037, 'en' => 1000, 'de' => 1001, 'fr' => 1002, 'es' => 1003, 'it' => 1004, 'ru' => 1031, 'ar' => 1019, 'nl' => 1010];

    private const array MONTHS = ['JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4, 'MAY' => 5, 'JUNE' => 6, 'JULY' => 7, 'AUGUST' => 8,
        'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12];

    public function __construct(
        private readonly GoogleApiClient $http,
        private readonly GoogleAdsRequestGovernor $governor,
        private readonly QueryServiceMatcher $matcher,
        private readonly QueryNormalizer $normalizer,
    ) {}

    /**
     * Every operational brand not asked within REFRESH_DAYS (or the given one).
     *
     * @return array{brands: int, ideas: int, kept: int, skipped: array<string, int>}
     */
    public function run(?int $brandId = null, bool $force = false): array
    {
        $out = ['brands' => 0, 'ideas' => 0, 'kept' => 0, 'skipped' => []];
        $ids = $brandId !== null ? [$brandId] : app(ServiceScope::class)->operationalBrandIds();
        foreach (Brand::query()->whereIn('id', $ids ?: [0])->orderBy('id')->get() as $brand) {
            if (! $force && Cache::has(self::lastRunKey((int) $brand->id))) {
                continue;
            }
            $result = $this->forBrand($brand);
            $out['brands']++;
            $out['ideas'] += $result['ideas'];
            $out['kept'] += $result['kept'];
            if ($result['status'] !== 'ready') {
                $out['skipped'][$result['status']] = ($out['skipped'][$result['status']] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** @return array{status: string, ideas: int, kept: int, message: ?string} */
    public function forBrand(Brand $brand): array
    {
        $result = $this->ask($brand);
        Cache::put(self::lastRunKey((int) $brand->id), $result + ['at' => now()->toIso8601String()], now()->addDays($result['status'] === 'ready' ? self::REFRESH_DAYS : 1));

        return $result;
    }

    /** The last run of a brand: {status, ideas, kept, message, at} or null. */
    public static function lastRun(int $brandId): ?array
    {
        $run = Cache::get(self::lastRunKey($brandId));

        return is_array($run) ? $run : null;
    }

    public static function lastRunKey(int $brandId): string
    {
        return 'keyword-planner:last-run:'.$brandId;
    }

    /** @return array{status: string, ideas: int, kept: int, message: ?string} */
    private function ask(Brand $brand): array
    {
        $offerings = SiteScope::offerings($brand);
        $services = $offerings->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($services === []) {
            return ['status' => 'no_services', 'ideas' => 0, 'kept' => 0, 'message' => 'Markanın hizmeti yok.'];
        }
        $home = SiteScope::resourceIds($brand, 'google_ads')[0] ?? SiteScope::resourceIds($brand, 'search_console')[0] ?? null;
        if ($home === null) {
            return ['status' => 'no_account', 'ideas' => 0, 'kept' => 0, 'message' => 'Markaya bağlı Google Ads ya da Search Console hesabı yok.'];
        }
        $account = $this->account($brand);
        if ($account === null) {
            return ['status' => 'no_ads_account', 'ideas' => 0, 'kept' => 0, 'message' => 'Planlayıcıyı çağıracak Google Ads hesabı yok.'];
        }
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->first();
        $language = $site !== null ? strtolower((string) (SiteScope::primaryLanguage($site) ?? 'tr')) : 'tr';
        $languageId = self::LANGUAGES[substr($language, 0, 2)] ?? self::LANGUAGES['tr'];
        $city = SiteScope::areaWord(SiteScope::targetArea($brand));
        $names = $offerings->map(fn (BrandOffering $o): string => QueryNormalizer::lower(trim($o->displayName())))->filter()->unique()->values();
        $seeds = $names->take(12)->merge($city !== '' ? $names->take(8)->map(fn (string $n): string => $n.' '.$city) : [])->take(self::SEEDS)->values()->all();

        [$integration, $customerId, $login] = $account;
        try {
            $response = $this->governor->run($integration, $customerId, fn (): Response => $this->http->keywordIdeas($integration, $customerId, [
                'language' => 'languageConstants/'.$languageId,
                'geoTargetConstants' => ['geoTargetConstants/'.self::TURKEY],
                'keywordPlanNetwork' => 'GOOGLE_SEARCH',
                'includeAdultKeywords' => false,
                'keywordSeed' => ['keywords' => $seeds],
                'pageSize' => 1000,
            ], $login));
        } catch (Throwable $exception) {
            return ['status' => 'error', 'ideas' => 0, 'kept' => 0, 'message' => mb_substr($exception->getMessage(), 0, 300)];
        }
        if (! $response->successful()) {
            $message = (string) (data_get($response->json(), 'error.details.0.errors.0.message') ?? data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status());

            return ['status' => 'error', 'ideas' => 0, 'kept' => 0, 'message' => 'Google Ads: '.mb_substr($message, 0, 300)];
        }

        $ideas = array_values(array_filter((array) data_get($response->json(), 'results', []), 'is_array'));
        $kept = [];
        foreach ($ideas as $idea) {
            $text = $this->normalizer->normalize((string) ($idea['text'] ?? ''));
            $searches = (int) data_get($idea, 'keywordIdeaMetrics.avgMonthlySearches', 0);
            if ($text === '' || $searches < self::MIN_SEARCHES || mb_strlen($text) > 80 || $this->normalizer->matchingTerm($text) !== null) {
                continue;
            }
            $service = $this->matcher->match($text, $brand->sector_id !== null ? (int) $brand->sector_id : null);
            if ($service === null || ! in_array($service, $services, true)) {
                continue;
            }
            $kept[$text] = ['searches' => $searches, 'metrics' => (array) ($idea['keywordIdeaMetrics'] ?? [])];
        }
        uasort($kept, fn (array $a, array $b): int => $b['searches'] <=> $a['searches']);
        $kept = array_slice($kept, 0, self::KEEP, true);
        $this->store($home, $kept, substr($language, 0, 2));

        return ['status' => 'ready', 'ideas' => count($ideas), 'kept' => count($kept),
            'message' => count($ideas).' fikir geldi, markanın hizmetlerine uyan '.count($kept).' arama kütüphaneye eklendi.'];
    }

    /**
     * Raw library rows for this month (a text that already has real data on that account this month is left alone),
     * search volumes, and the volume of library queries that already exist.
     *
     * @param  array<string, array{searches: int, metrics: array<string, mixed>}>  $kept
     */
    private function store(int $resourceId, array $kept, string $language): void
    {
        if ($kept === []) {
            return;
        }
        $month = CarbonImmutable::now()->startOfMonth()->toDateString();
        $now = now();
        $existing = array_flip(DB::table('query_sources')->where('external_resource_id', $resourceId)->where('month', $month)
            ->whereIn('raw_query', array_keys($kept))->where('source', '!=', self::SOURCE)->pluck('raw_query')->all());
        $rows = [];
        foreach ($kept as $text => $idea) {
            if (! isset($existing[$text])) {
                $rows[] = ['external_resource_id' => $resourceId, 'source' => self::SOURCE, 'raw_query' => $text, 'month' => $month, 'impressions' => $idea['searches'],
                    'clicks' => 0, 'position' => null, 'cost' => null, 'conversions' => null, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('query_sources')->upsert($chunk, ['external_resource_id', 'raw_query', 'month'], ['impressions', 'updated_at']);
        }
        foreach ($kept as $text => $idea) {
            $normalized = SerpResults::normalize($text);
            $monthly = array_values(array_map(fn (array $m): array => ['year' => (int) ($m['year'] ?? 0), 'month' => self::MONTHS[(string) ($m['month'] ?? '')] ?? 0,
                'volume' => is_numeric($m['monthlySearches'] ?? null) ? (int) $m['monthlySearches'] : null], array_filter((array) ($idea['metrics']['monthlySearchVolumes'] ?? []), 'is_array')));
            $low = data_get($idea['metrics'], 'lowTopOfPageBidMicros');
            $high = data_get($idea['metrics'], 'highTopOfPageBidMicros');
            DB::table('query_volumes')->updateOrInsert(
                ['query_hash' => hash('sha256', $normalized), 'location_code' => self::TURKEY, 'language_code' => $language],
                ['query' => mb_substr($normalized, 0, 500), 'volume' => $idea['searches'], 'monthly' => $monthly !== [] ? json_encode($monthly) : null,
                    'competition' => is_numeric($idea['metrics']['competitionIndex'] ?? null) ? round((float) $idea['metrics']['competitionIndex'] / 100, 4) : null,
                    'cpc' => is_numeric($low) && is_numeric($high) ? round(((float) $low + (float) $high) / 2 / 1_000_000, 4) : null,
                    'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            );
            DB::table('queries')->where('text_hash', QueryNormalizer::hash($text))->update(['volume' => $idea['searches'], 'updated_at' => $now]);
        }
    }

    /**
     * The Google Ads account the planner is called through: the brand's own, else any available account (planning
     * does not depend on the account's data).
     *
     * @return array{0: CoreIntegration, 1: string, 2: string}|null
     */
    private function account(Brand $brand): ?array
    {
        $own = SiteScope::resourceIds($brand, 'google_ads');
        $resource = CoreExternalResource::query()->with('integration')->where('resource_type', 'google_ads')
            ->when($own !== [], fn ($q) => $q->orderByRaw('CASE WHEN id IN ('.implode(',', $own).') THEN 0 ELSE 1 END'))
            ->whereHas('integration')->where('status', '!=', 'unavailable')->orderBy('id')->first();
        if ($resource === null || ! $resource->integration instanceof CoreIntegration) {
            return null;
        }
        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $customerId = preg_replace('/\D+/', '', (string) $resource->external_id) ?: '';
        if ($customerId === '') {
            return null;
        }
        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;

        return [$resource->integration, $customerId, $login];
    }
}
