<?php

namespace App\Services\Advisor\Cross;

use App\Models\DigitalAsset;
use App\Services\Advisor\Gbp\GbpAdvisorInputCollector;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;

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
        foreach (DigitalAsset::query()->where('brand_id', $site->brand_id)->whereIn('type', ['google_business_profile', 'gbp'])->where('status', 'active')->get() as $asset) {
            $input = $this->gbpCollector->collect($asset);
            foreach ($input['keywords']['items'] ?? [] as $row) {
                $gbpKeywords[$row['keyword']] = ($gbpKeywords[$row['keyword']] ?? 0) + $row['impressions'];
            }
        }
        if ($ads === [] && $gbpKeywords === []) {
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
        ];
    }
}
