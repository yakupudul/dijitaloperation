<?php

namespace App\Services\Advisor\Cross;

use App\Models\DigitalAsset;
use App\Services\Advisor\Gbp\GbpAdvisorInputCollector;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\Assistant\WhatsAppContactLinker;
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
        if ($ads === [] && $gbpKeywords === [] && $gbpProfiles === [] && $consistency['ads_landing_hosts'] === [] && $consistency['meta_hosts'] === []) {
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
        ];
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
