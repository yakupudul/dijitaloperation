<?php

namespace App\Services\Advisor\Cross;

use App\Models\BrandConversionSource;
use App\Models\DigitalAsset;
use App\Services\Advisor\Gbp\GbpAdvisorInputCollector;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\BrandMeasurementScope;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use App\Services\Website\PublicDiscovery\StoredHtmlReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * For one website: the brand's Google Ads search terms, Business Profile search keywords, Search Console
 * query rows and crawled pages — the inputs of the cross-channel rules. Stored data only.
 */
final class CrossChannelInputCollector
{
    public function __construct(
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly GoogleAdsAdvisorInputCollector $adsCollector,
        private readonly GbpAdvisorInputCollector $gbpCollector,
        private readonly AdvisorWebsiteReader $websiteReader,
        private readonly StoredHtmlReader $htmlReader,
    ) {}

    /** @return array<string, mixed> */
    public function collect(DigitalAsset $site): array
    {
        $site->loadMissing('brand');
        $base = [
            'asset' => ['id' => $site->id, 'name' => $site->domain ?: $site->name, 'brand_id' => $site->brand_id, 'customer_id' => $site->brand?->customer_id, 'brand_name' => $site->brand?->name],
        ];
        if ($site->brand_id === null) {
            return $base + ['bound' => false, 'binding_reason' => 'no_brand'];
        }

        $ads = [];
        $currency = null;
        foreach (DigitalAsset::query()->where('brand_id', $site->brand_id)->where('type', 'google_ads')->where('status', 'active')->get() as $asset) {
            $input = $this->adsCollector->collect($asset);
            if (! ($input['bound'] ?? false)) {
                continue;
            }
            $currency ??= $input['currency'] ?? null;
            foreach ($input['search_terms'] as $key => $term) {
                $entry = $ads[$key] ?? ['term' => $term['term'], 'cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
                $entry['cost'] += $term['cost'];
                $entry['clicks'] += $term['clicks'];
                $entry['conversions'] += $term['conversions'];
                $ads[$key] = $entry;
            }
        }
        $gbpKeywords = [];
        $gbpProfiles = [];
        foreach (DigitalAsset::query()->where('brand_id', $site->brand_id)->whereIn('type', ['google_business_profile', 'gbp'])->where('status', 'active')->get() as $asset) {
            $input = $this->gbpCollector->collect($asset);
            foreach ($input['keywords']['items'] ?? [] as $row) {
                $gbpKeywords[$row['keyword']] = ($gbpKeywords[$row['keyword']] ?? 0) + $row['impressions'];
            }
            if (is_array($input['location'] ?? null)) {
                $gbpProfiles[] = ['name' => (string) ($input['location']['title'] ?? $asset->name), 'website_uri' => $input['location']['website_uri'] ?? null, 'phones' => (array) ($input['location']['phones'] ?? [])];
            }
        }
        $consistency = $this->consistency($site, $gbpProfiles);
        $channelSpend = $this->channelSpend($site);
        $season = $this->season($site);
        if ($ads === [] && $gbpKeywords === [] && $gbpProfiles === [] && $consistency['ads_landing_hosts'] === [] && $consistency['meta_hosts'] === [] && $season === null) {
            return $base + ['bound' => false, 'binding_reason' => 'no_partner_channels'];
        }

        $end = CarbonImmutable::now('UTC')->subDays(3)->startOfDay();
        $gsc = $this->seoInputs->gsc($site, $end->subDays((int) config('moxdop-advisor.cross.gsc_days', 90) - 1), $end);
        $queries = [];
        foreach ($gsc['rows'] ?? [] as $row) {
            $key = SeoText::fold((string) $row['query']);
            $entry = $queries[$key] ?? ['impressions' => 0, 'clicks' => 0, 'weighted_position' => 0.0];
            $entry['impressions'] += (int) $row['impressions'];
            $entry['clicks'] += (int) $row['clicks'];
            $entry['weighted_position'] += (float) ($row['position'] ?? 0) * (int) $row['impressions'];
            $queries[$key] = $entry;
        }
        foreach ($queries as $key => $entry) {
            $queries[$key]['position'] = $entry['impressions'] > 0 ? $entry['weighted_position'] / $entry['impressions'] : null;
            unset($queries[$key]['weighted_position']);
        }

        $website = $this->websiteReader->forBrandOf($site);
        $pages = [];
        foreach ($website['pages'] as $page) {
            if (($page['status_code'] ?? 200) >= 400) {
                continue;
            }
            $pages[] = ['url' => $page['url'], 'text' => trim(implode(' ', array_filter([(string) $page['title'], (string) $page['h1'], SeoText::slugText((string) $page['url'])])))];
        }
        arsort($gbpKeywords);

        return $base + [
            'bound' => true,
            'binding_reason' => null,
            'currency' => $currency,
            'period' => ['end' => $end->toDateString(), 'days' => (int) config('moxdop-advisor.cross.gsc_days', 90)],
            'ads_terms' => $ads,
            'gbp_keywords' => $gbpKeywords,
            'gsc_available' => (bool) ($gsc['available'] ?? false),
            'gsc_queries' => $queries,
            'pages' => $pages,
            'consistency' => $consistency,
            'channel_spend' => $channelSpend,
            'season' => $season,
        ];
    }

    /**
     * Faz 14: last 28 days of spend and counted conversions per paid channel (Google Ads, Meta). Conversions
     * come from the brand's conversion dictionary; without a counted Meta conversion Meta has no CPA.
     *
     * @return array{days: int, google_ads: array{cost: float, conversions: ?float}, meta: array{cost: float, conversions: ?float}}|null
     */
    private function channelSpend(DigitalAsset $site): ?array
    {
        $brand = $site->brand;
        $scope = $brand !== null ? BrandMeasurementScope::for($brand) : null;
        if ($scope === null || $scope->isEmpty()) {
            return null;
        }
        $days = (int) config('moxdop-advisor.cross.budget_shift_days', 28);
        $to = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $from = $to->subDays($days - 1);
        $sum = function (string $table, string $column) use ($scope, $from, $to): float {
            if (! Schema::hasTable($table)) {
                return 0.0;
            }

            return (float) $scope->apply(DB::table($table))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])->sum($column);
        };
        $googleCost = $sum('google_ads_campaign_daily', 'cost_amount');
        $metaCost = $sum('meta_campaign_daily', 'spend');
        if ($googleCost <= 0 && $metaCost <= 0) {
            return null;
        }
        $bySource = app(BrandConversionDictionary::class)->totals($brand, $from, $to)['by_source'];
        $googleConversions = $bySource[BrandConversionSource::SOURCE_GOOGLE_ADS] ?? null;
        if ($googleConversions === null && $googleCost > 0) {
            $googleConversions = $sum('google_ads_campaign_daily', 'conversions');
        }

        return [
            'days' => $days,
            'google_ads' => ['cost' => round($googleCost, 2), 'conversions' => $googleConversions !== null ? round((float) $googleConversions, 2) : null],
            'meta' => ['cost' => round($metaCost, 2), 'conversions' => isset($bySource[BrandConversionSource::SOURCE_META]) ? round((float) $bySource[BrandConversionSource::SOURCE_META], 2) : null],
        ];
    }

    /**
     * Faz 14: last year's organic clicks for the coming weeks against the weeks before them (Search Console,
     * web). A season that starts soon shows up here before it shows up in this year's data.
     *
     * @return array{window_days: int, ahead_clicks: int, before_clicks: int, ahead_from: string, ahead_to: string}|null
     */
    private function season(DigitalAsset $site): ?array
    {
        $brand = $site->brand;
        $scope = $brand !== null ? BrandMeasurementScope::for($brand) : null;
        if ($scope === null || $scope->isEmpty() || ! Schema::hasTable('gsc_property_daily')) {
            return null;
        }
        $window = (int) config('moxdop-advisor.cross.season_window_days', 60);
        $pivot = CarbonImmutable::now('UTC')->startOfDay()->subYear();
        $clicks = fn (CarbonImmutable $from, CarbonImmutable $to): int => (int) $scope->apply(DB::table('gsc_property_daily'))
            ->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('search_type')->orWhere('search_type', 'web'))->sum('clicks');
        $before = $clicks($pivot->subDays($window), $pivot->subDay());
        $ahead = $clicks($pivot, $pivot->addDays($window - 1));
        if ($before === 0 && $ahead === 0) {
            return null;
        }

        return ['window_days' => $window, 'ahead_clicks' => $ahead, 'before_clicks' => $before, 'ahead_from' => $pivot->addYear()->toDateString(), 'ahead_to' => $pivot->addYear()->addDays($window - 1)->toDateString()];
    }

    /**
     * Faz 7 (cross-asset consistency from current data): the brand's website hosts, phone numbers on the stored
     * home / contact pages, Business Profile phones and website, Google Ads landing hosts with spend (30 days)
     * and Meta ad destination hosts.
     *
     * @param  list<array{name: string, website_uri: ?string, phones: list<string>}>  $gbpProfiles
     * @return array<string, mixed>
     */
    private function consistency(DigitalAsset $site, array $gbpProfiles): array
    {
        $host = static fn (?string $url): string => preg_replace('/^www\./', '', mb_strtolower((string) parse_url((string) $url, PHP_URL_HOST))) ?? '';
        $siteHosts = DigitalAsset::query()->where('brand_id', $site->brand_id)->where('type', 'website')->get(['primary_url', 'domain'])
            ->map(fn ($s): string => $host($s->primary_url ?: 'https://'.$s->domain))->filter()->unique()->values()->all();

        $sitePhones = [];
        $sitePhonesRead = false;
        if (Schema::hasTable('website_html_snapshot')) {
            $snapshots = DB::table('website_html_snapshot')->where('digital_asset_id', $site->id)->whereNotNull('raw_ingestion_object_id')
                ->orderByDesc('observed_at')->limit(300)->get(['id', 'url'])->unique('url')
                ->filter(fn ($row): bool => in_array(rtrim((string) parse_url((string) $row->url, PHP_URL_PATH), '/'), ['', '/iletisim', '/contact', '/bize-ulasin', '/iletisim-bilgileri'], true)
                    || preg_match('/iletisim|contact|ulasin/i', (string) $row->url) === 1)
                ->take(4);
            foreach ($snapshots as $snapshot) {
                try {
                    $page = $this->htmlReader->read($site, (string) $snapshot->url, (int) $snapshot->id);
                } catch (\Throwable) {
                    continue;
                }
                if ($page === null) {
                    continue;
                }
                $sitePhonesRead = true;
                preg_match_all('/(?:tel:|\+?90[\s.-]?|\b0[\s.-]?)?\(?[2-5]\d{2}\)?[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}\b/', $page['html'], $matches);
                foreach ($matches[0] as $match) {
                    if (($key = WhatsAppContactLinker::key($match)) !== null) {
                        $sitePhones[$key] = true;
                    }
                }
            }
        }

        $adsHosts = [];
        $scope = $site->brand !== null ? BrandMeasurementScope::for($site->brand) : null;
        if ($scope !== null && ! $scope->isEmpty() && Schema::hasTable('google_ads_landing_page_daily')) {
            $scope->apply(DB::table('google_ads_landing_page_daily'))->where('reporting_date', '>=', now()->subDays(30)->toDateString())
                ->groupBy('landing_page')->selectRaw('landing_page, sum(cost_amount) as cost')->get()
                ->each(function (object $row) use (&$adsHosts, $host): void {
                    $h = $host((string) $row->landing_page);
                    if ($h !== '') {
                        $adsHosts[$h] = round(($adsHosts[$h] ?? 0) + (float) $row->cost, 2);
                    }
                });
        }
        $metaHosts = [];
        if ($scope !== null && ! $scope->isEmpty() && Schema::hasTable('meta_creative_snapshot')) {
            foreach ($scope->apply(DB::table('meta_creative_snapshot'))->orderByDesc('last_collected_at')->limit(1000)->get(['creative_id', 'metadata'])->unique('creative_id') as $row) {
                $h = $host((string) (json_decode((string) $row->metadata, true)['link_url'] ?? ''));
                if ($h !== '') {
                    $metaHosts[$h] = ($metaHosts[$h] ?? 0) + 1;
                }
            }
        }

        return [
            'site_hosts' => $siteHosts,
            'site_phones' => array_keys($sitePhones),
            'site_phones_read' => $sitePhonesRead,
            'gbp_profiles' => $gbpProfiles,
            'ads_landing_hosts' => $adsHosts,
            'meta_hosts' => $metaHosts,
        ];
    }
}
