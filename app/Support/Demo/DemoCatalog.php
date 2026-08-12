<?php

namespace App\Support\Demo;

/**
 * Deterministic, isolated DEMO fixtures for the full-product interactive UI.
 *
 * Never writes to the operator database. All analytical/provider data lives here
 * (or in session via {@see DemoState}). Restart with `php artisan dop:demo-reset`
 * or the in-app Reset control.
 */
final class DemoCatalog
{
    public const string CUSTOMER_ID = 'atlas-health';

    public const string BRAND_ID = 'atlas-dental';

    public const string META_ASSET_ID = 'meta-atlas';

    public const string GOOGLE_ADS_ASSET_ID = 'gads-atlas';

    public const string WEBSITE_ASSET_ID = 'web-atlas';

    public const string GBP_ASSET_ID = 'gbp-atlas';

    public const string GA4_ASSET_ID = 'ga4-atlas';

    public const string GSC_ASSET_ID = 'gsc-atlas';

    public const string DOMAIN_ASSET_ID = 'domain-atlas';

    public const string HOSTING_ASSET_ID = 'hosting-atlas';

    /**
     * @return array{label: string, customer: array<string, mixed>, brand: array<string, mixed>, assets: list<array<string, mixed>>}
     */
    public static function portfolio(): array
    {
        return [
            'label' => 'Demo Mode',
            'customer' => self::customer(),
            'brand' => self::brand(),
            'assets' => self::assets(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(): array
    {
        return [
            'id' => self::CUSTOMER_ID,
            'name' => 'Atlas Health Group',
            'industry' => 'Healthcare',
            'hq' => 'Ankara, Türkiye',
            'brands_count' => 1,
            'open_issues' => 4,
            'open_tasks' => 3,
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(): array
    {
        return [
            'id' => self::BRAND_ID,
            'customer_id' => self::CUSTOMER_ID,
            'name' => 'Atlas Dental Ankara',
            'industry' => 'Dental / Healthcare',
            'location' => 'Ankara, Türkiye',
            'website' => 'https://atlasdental.example',
            'health' => 'needs_attention',
            'health_label' => 'Needs attention',
            'assets_count' => 8,
            'open_tasks' => 3,
            'summary' => [
                'media_spend' => 280800,
                'platform_leads' => 438,
                'website_leads' => 202,
                'calls_messages' => 1146,
                'currency' => 'TRY',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function assets(): array
    {
        return [
            [
                'id' => self::WEBSITE_ASSET_ID,
                'type' => 'website',
                'type_label' => 'Website',
                'name' => 'atlasdental.example',
                'brand_id' => self::BRAND_ID,
                'connection' => 'public_plus_detected',
                'provenance' => 'Public + Detected',
                'health' => 'needs_attention',
                'health_label' => 'Needs attention',
                'score' => 72,
                'open_findings' => 3,
                'open_tasks' => 1,
                'last_update' => 'Today',
                'route' => 'demo.website',
            ],
            [
                'id' => self::GBP_ASSET_ID,
                'type' => 'gbp',
                'type_label' => 'Google Business Profile',
                'name' => 'Atlas Dental Ankara',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'score' => 89,
                'rating' => 4.8,
                'open_findings' => 1,
                'open_tasks' => 0,
                'last_update' => 'Yesterday',
                'route' => 'demo.gbp',
            ],
            [
                'id' => self::META_ASSET_ID,
                'type' => 'meta_ads',
                'type_label' => 'Meta Ads',
                'name' => 'Atlas Dental — Meta',
                'external_id' => 'act_demo_7446541',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'needs_attention',
                'health_label' => 'Needs attention',
                'detail' => 'CPL deteriorating',
                'open_findings' => 2,
                'open_tasks' => 1,
                'last_update' => '2 hours ago',
                'route' => 'demo.meta.overview',
            ],
            [
                'id' => self::GOOGLE_ADS_ASSET_ID,
                'type' => 'google_ads',
                'type_label' => 'Google Ads',
                'name' => 'Atlas Dental — Google Ads',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'detail' => 'CPA stable',
                'open_findings' => 1,
                'open_tasks' => 1,
                'last_update' => 'Today',
                'route' => 'demo.google-ads.overview',
            ],
            [
                'id' => self::GA4_ASSET_ID,
                'type' => 'ga4',
                'type_label' => 'Google Analytics',
                'name' => 'Atlas Dental GA4',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'healthy',
                'health_label' => 'Connected',
                'open_findings' => 0,
                'open_tasks' => 0,
                'last_update' => 'Today',
                'route' => 'demo.analytics',
            ],
            [
                'id' => self::GSC_ASSET_ID,
                'type' => 'gsc',
                'type_label' => 'Search Console',
                'name' => 'atlasdental.example',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'healthy',
                'health_label' => 'Connected',
                'open_findings' => 0,
                'open_tasks' => 0,
                'last_update' => 'Today',
                'route' => 'demo.search-console',
            ],
            [
                'id' => self::DOMAIN_ASSET_ID,
                'type' => 'domain',
                'type_label' => 'Domain',
                'name' => 'atlasdental.example',
                'brand_id' => self::BRAND_ID,
                'connection' => 'detected',
                'provenance' => 'Detected',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'detail' => '221 days remaining',
                'open_findings' => 0,
                'open_tasks' => 0,
                'last_update' => 'Detected',
                'route' => 'demo.website',
            ],
            [
                'id' => self::HOSTING_ASSET_ID,
                'type' => 'hosting',
                'type_label' => 'Hosting / Infrastructure',
                'name' => 'DemoHost · Atlas Dental',
                'brand_id' => self::BRAND_ID,
                'connection' => 'manual',
                'provenance' => 'Manual',
                'health' => 'warning',
                'health_label' => 'Renewal due',
                'detail' => '34 days',
                'open_findings' => 1,
                'open_tasks' => 0,
                'last_update' => 'Manual',
                'route' => 'demo.website',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function asset(string $id): ?array
    {
        foreach (self::assets() as $asset) {
            if ($asset['id'] === $id) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Period multipliers so demo date changes visibly alter metrics (deterministic).
     *
     * @return array{spend_factor: float, results_factor: float, label: string}
     */
    public static function periodFactors(string $preset): array
    {
        return match ($preset) {
            'last_7' => ['spend_factor' => 0.28, 'results_factor' => 0.26, 'label' => 'Last 7 days'],
            'last_14' => ['spend_factor' => 0.52, 'results_factor' => 0.50, 'label' => 'Last 14 days'],
            'last_28' => ['spend_factor' => 1.00, 'results_factor' => 1.00, 'label' => 'Last 28 days'],
            'last_30' => ['spend_factor' => 1.07, 'results_factor' => 1.05, 'label' => 'Last 30 days'],
            'this_month' => ['spend_factor' => 0.62, 'results_factor' => 0.60, 'label' => 'This month'],
            'last_month' => ['spend_factor' => 1.12, 'results_factor' => 1.08, 'label' => 'Last month'],
            'custom' => ['spend_factor' => 0.88, 'results_factor' => 0.90, 'label' => 'Custom range'],
            default => ['spend_factor' => 1.00, 'results_factor' => 1.00, 'label' => 'Last 28 days'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function metaOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        $spend = round(184320 * $f['spend_factor']);
        $leads = (int) round(312 * $f['results_factor']);
        $msg = (int) round(1487 * $f['results_factor']);

        return [
            'period_label' => $f['label'],
            'kpis' => [
                ['key' => 'spend', 'label' => 'Spend', 'value' => $spend, 'format' => 'try', 'delta' => 12.4, 'tone' => 'neutral', 'family' => 'spend'],
                ['key' => 'leads', 'label' => 'Leads', 'value' => $leads, 'format' => 'int', 'delta' => -8.2, 'tone' => 'bad', 'family' => 'result'],
                ['key' => 'cpl', 'label' => 'Cost / Lead', 'value' => $leads > 0 ? round($spend / $leads, 2) : 0, 'format' => 'try', 'delta' => 22.1, 'tone' => 'bad', 'family' => 'efficiency'],
                ['key' => 'messaging', 'label' => 'Messaging Conversations', 'value' => $msg, 'format' => 'int', 'delta' => 6.1, 'tone' => 'good', 'family' => 'result'],
                ['key' => 'reach', 'label' => 'Reach', 'value' => (int) round(482400 * $f['results_factor']), 'format' => 'int', 'delta' => 3.2, 'tone' => 'neutral', 'family' => 'delivery'],
                ['key' => 'frequency', 'label' => 'Frequency', 'value' => 2.21, 'format' => 'float', 'delta' => 4.0, 'tone' => 'warn', 'family' => 'delivery'],
                ['key' => 'ctr', 'label' => 'Link CTR', 'value' => 2.84, 'format' => 'pct', 'delta' => -11.0, 'tone' => 'bad', 'family' => 'delivery'],
                ['key' => 'cpm', 'label' => 'CPM', 'value' => 176.40, 'format' => 'try', 'delta' => -5.4, 'tone' => 'good', 'family' => 'efficiency'],
            ],
            'attention' => [
                [
                    'severity' => 'high',
                    'title' => 'Post Bariatric — Europe',
                    'body' => 'Cost / Lead rose from ₺482 → ₺691 (+43%). Spend remains material. Main deterioration: Creative PB-Video-03 (Link CTR 2.9% → 1.4%).',
                    'source' => 'Meta Ads · Connected provider',
                    'action' => 'Inspect campaign',
                    'route' => 'demo.meta.campaign',
                    'route_params' => ['campaignId' => 'camp-pb-eu'],
                ],
            ],
            'trend' => self::trendSeries('spend', (int) round(18 * $f['spend_factor']), 4200, 8200),
            'campaigns' => self::metaCampaigns($preset),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function metaCampaigns(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            [
                'id' => 'camp-pb-eu',
                'name' => 'Post Bariatric — Europe',
                'status' => 'ACTIVE',
                'objective' => 'OUTCOME_LEADS',
                'spend' => round(68420 * $f['spend_factor']),
                'results' => (int) round(99 * $f['results_factor']),
                'result_label' => 'Leads',
                'cost_result' => 691,
                'reach' => (int) round(92000 * $f['results_factor']),
                'frequency' => 2.4,
                'ctr' => 1.4,
                'attention' => 'high',
                'trend' => [4, 5, 6, 7, 8, 9, 11],
            ],
            [
                'id' => 'camp-impl-tr',
                'name' => 'Implant — Türkiye',
                'status' => 'ACTIVE',
                'objective' => 'OUTCOME_LEADS',
                'spend' => round(52110 * $f['spend_factor']),
                'results' => (int) round(118 * $f['results_factor']),
                'result_label' => 'Leads',
                'cost_result' => 442,
                'reach' => (int) round(140000 * $f['results_factor']),
                'frequency' => 2.0,
                'ctr' => 3.1,
                'attention' => null,
                'trend' => [6, 6, 7, 7, 8, 8, 7],
            ],
            [
                'id' => 'camp-msg-local',
                'name' => 'Messaging — Local Ankara',
                'status' => 'ACTIVE',
                'objective' => 'OUTCOME_ENGAGEMENT',
                'spend' => round(38400 * $f['spend_factor']),
                'results' => (int) round(860 * $f['results_factor']),
                'result_label' => 'Messaging conversations',
                'cost_result' => 45,
                'reach' => (int) round(110000 * $f['results_factor']),
                'frequency' => 1.9,
                'ctr' => 2.9,
                'attention' => null,
                'trend' => [5, 6, 6, 7, 8, 9, 9],
            ],
            [
                'id' => 'camp-retarget',
                'name' => 'Retargeting — Form',
                'status' => 'PAUSED',
                'objective' => 'OUTCOME_LEADS',
                'spend' => round(25390 * $f['spend_factor']),
                'results' => (int) round(95 * $f['results_factor']),
                'result_label' => 'Leads',
                'cost_result' => 267,
                'reach' => (int) round(48000 * $f['results_factor']),
                'frequency' => 3.1,
                'ctr' => 2.2,
                'attention' => 'medium',
                'trend' => [8, 7, 6, 5, 5, 4, 4],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metaCampaign(string $id, string $preset = 'last_28'): ?array
    {
        foreach (self::metaCampaigns($preset) as $campaign) {
            if ($campaign['id'] === $id) {
                $campaign['adsets'] = self::metaAdSets($id, $preset);
                $campaign['kpis'] = [
                    ['label' => 'Spend', 'value' => $campaign['spend'], 'format' => 'try'],
                    ['label' => $campaign['result_label'], 'value' => $campaign['results'], 'format' => 'int'],
                    ['label' => 'Cost / Result', 'value' => $campaign['cost_result'], 'format' => 'try'],
                    ['label' => 'Link CTR', 'value' => $campaign['ctr'], 'format' => 'pct'],
                ];

                return $campaign;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function metaAdSets(string $campaignId, string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            [
                'id' => $campaignId.'-as1',
                'name' => 'Broad 25–54',
                'status' => 'ACTIVE',
                'spend' => round(28000 * $f['spend_factor']),
                'results' => (int) round(48 * $f['results_factor']),
                'ctr' => 1.6,
            ],
            [
                'id' => $campaignId.'-as2',
                'name' => 'Lookalike purchasers',
                'status' => 'ACTIVE',
                'spend' => round(22000 * $f['spend_factor']),
                'results' => (int) round(36 * $f['results_factor']),
                'ctr' => 1.3,
            ],
            [
                'id' => $campaignId.'-as3',
                'name' => 'Retarget site 30d',
                'status' => 'ACTIVE',
                'spend' => round(18420 * $f['spend_factor']),
                'results' => (int) round(15 * $f['results_factor']),
                'ctr' => 2.4,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function metaCreatives(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            [
                'id' => 'cr-pb-video-03',
                'name' => 'PB-Video-03',
                'format' => 'Video',
                'campaign' => 'Post Bariatric — Europe',
                'spend' => round(41200 * $f['spend_factor']),
                'result' => (int) round(42 * $f['results_factor']),
                'result_label' => 'Leads',
                'cost_result' => 981,
                'ctr' => 1.4,
                'frequency' => 2.8,
                'attention' => 'high',
                'headline' => 'Transform your confidence',
                'copy' => 'Specialist post-bariatric body contouring in Ankara. Book a consultation.',
                'cta' => 'Learn more',
                'destination' => 'https://atlasdental.example/post-bariatric',
                'preview' => 'video',
            ],
            [
                'id' => 'cr-impl-static-01',
                'name' => 'Implant-Static-01',
                'format' => 'Image',
                'campaign' => 'Implant — Türkiye',
                'spend' => round(29800 * $f['spend_factor']),
                'result' => (int) round(71 * $f['results_factor']),
                'result_label' => 'Leads',
                'cost_result' => 420,
                'ctr' => 3.4,
                'frequency' => 1.9,
                'attention' => null,
                'headline' => 'Dental implants with specialists',
                'copy' => 'Atlas Dental Ankara — transparent plans, experienced surgeons.',
                'cta' => 'Get quote',
                'destination' => 'https://atlasdental.example/implant',
                'preview' => 'image',
            ],
            [
                'id' => 'cr-msg-carousel',
                'name' => 'Local-Carousel-02',
                'format' => 'Carousel',
                'campaign' => 'Messaging — Local Ankara',
                'spend' => round(18600 * $f['spend_factor']),
                'result' => (int) round(410 * $f['results_factor']),
                'result_label' => 'Conversations',
                'cost_result' => 45,
                'ctr' => 3.0,
                'frequency' => 1.7,
                'attention' => null,
                'headline' => 'Message us on WhatsApp',
                'copy' => 'Same-day answers from Atlas Dental coordinators.',
                'cta' => 'Send message',
                'destination' => 'https://atlasdental.example',
                'preview' => 'carousel',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function googleAdsOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        $spend = round(96480 * $f['spend_factor']);
        $conv = (int) round(126 * $f['results_factor']);

        return [
            'period_label' => $f['label'],
            'kpis' => [
                ['label' => 'Spend', 'value' => $spend, 'format' => 'try', 'delta' => 4.2, 'tone' => 'neutral', 'family' => 'spend'],
                ['label' => 'Conversions', 'value' => $conv, 'format' => 'int', 'delta' => 2.1, 'tone' => 'good', 'family' => 'result'],
                ['label' => 'CPA', 'value' => $conv > 0 ? round($spend / $conv, 2) : 0, 'format' => 'try', 'delta' => 1.8, 'tone' => 'neutral', 'family' => 'efficiency'],
                ['label' => 'Clicks', 'value' => (int) round(9842 * $f['results_factor']), 'format' => 'int', 'delta' => 5.0, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'CTR', 'value' => 8.20, 'format' => 'pct', 'delta' => 0.4, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Avg CPC', 'value' => 9.80, 'format' => 'try', 'delta' => -2.1, 'tone' => 'good', 'family' => 'efficiency'],
                ['label' => 'Conversion Rate', 'value' => 1.28, 'format' => 'pct', 'delta' => -0.3, 'tone' => 'warn', 'family' => 'efficiency'],
                ['label' => 'Impression Share', 'value' => 68, 'format' => 'pct', 'delta' => 1.0, 'tone' => 'neutral', 'family' => 'delivery'],
            ],
            'attention' => [
                [
                    'severity' => 'high',
                    'title' => 'Search Term Waste',
                    'body' => '₺12,240 spent on low-relevance search queries (12.7% of total spend).',
                    'source' => 'Google Ads · Connected provider',
                ],
                [
                    'severity' => 'medium',
                    'title' => 'Landing Page',
                    'body' => 'Campaign “Implant Search” sends 41% of paid traffic to a page with weak mobile performance.',
                    'source' => 'Google Ads + Website',
                ],
            ],
            'search_terms' => [
                ['term' => 'ucuz diş implantı', 'campaign' => 'Implant Search', 'spend' => 4200, 'clicks' => 310, 'conversions' => 1, 'cpa' => 4200, 'relevance' => 'low', 'action' => 'Negative candidate'],
                ['term' => 'implant ankara fiyat', 'campaign' => 'Implant Search', 'spend' => 3800, 'clicks' => 220, 'conversions' => 9, 'cpa' => 422, 'relevance' => 'high', 'action' => 'Keep'],
                ['term' => 'dişçi oyunu', 'campaign' => 'Brand Broad', 'spend' => 2100, 'clicks' => 540, 'conversions' => 0, 'cpa' => null, 'relevance' => 'low', 'action' => 'Negative candidate'],
                ['term' => 'atlas dental ankara', 'campaign' => 'Brand', 'spend' => 980, 'clicks' => 140, 'conversions' => 18, 'cpa' => 54, 'relevance' => 'high', 'action' => 'Keep'],
                ['term' => 'zirkonyum diş ankara', 'campaign' => 'Implant Search', 'spend' => 1160, 'clicks' => 95, 'conversions' => 4, 'cpa' => 290, 'relevance' => 'medium', 'action' => 'Review'],
            ],
            'campaigns' => [
                ['name' => 'Implant Search', 'status' => 'ENABLED', 'spend' => round(48200 * $f['spend_factor']), 'conv' => (int) round(62 * $f['results_factor']), 'cpa' => 777],
                ['name' => 'Brand', 'status' => 'ENABLED', 'spend' => round(18400 * $f['spend_factor']), 'conv' => (int) round(40 * $f['results_factor']), 'cpa' => 460],
                ['name' => 'Competitor', 'status' => 'PAUSED', 'spend' => round(9800 * $f['spend_factor']), 'conv' => (int) round(8 * $f['results_factor']), 'cpa' => 1225],
            ],
            'keywords' => [
                ['keyword' => 'implant ankara', 'match' => 'Phrase', 'spend' => 9200, 'clicks' => 640, 'conv' => 22],
                ['keyword' => 'diş kliniği çankaya', 'match' => 'Exact', 'spend' => 4100, 'clicks' => 210, 'conv' => 11],
            ],
            'landing_pages' => [
                ['url' => '/implant', 'sessions' => 4200, 'conv_rate' => 1.9, 'mobile_lcp' => '4.1s', 'note' => 'Weak mobile'],
                ['url' => '/', 'sessions' => 2100, 'conv_rate' => 0.8, 'mobile_lcp' => '2.4s', 'note' => 'OK'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function websiteOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'kpis' => [
                ['label' => 'Sessions', 'value' => (int) round(23840 * $f['results_factor']), 'format' => 'int', 'delta' => 6.2, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Organic Clicks', 'value' => (int) round(8420 * $f['results_factor']), 'format' => 'int', 'delta' => 3.1, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Website Leads', 'value' => (int) round(202 * $f['results_factor']), 'format' => 'int', 'delta' => -4.0, 'tone' => 'bad', 'family' => 'result'],
                ['label' => 'Conversion Rate', 'value' => 0.85, 'format' => 'pct', 'delta' => -0.1, 'tone' => 'warn', 'family' => 'efficiency'],
                ['label' => 'Indexed Pages', 'value' => '184 / 197', 'format' => 'text', 'delta' => null, 'tone' => 'neutral', 'family' => 'delivery'],
                ['label' => 'Technical Health', 'value' => 'Needs attention', 'format' => 'text', 'delta' => null, 'tone' => 'warn', 'family' => 'efficiency'],
            ],
            'technical' => [
                ['severity' => 'high', 'title' => 'Primary landing page mobile LCP', 'detail' => '4.1s on /implant'],
                ['severity' => 'medium', 'title' => 'Missing canonical', 'detail' => '7 pages'],
                ['severity' => 'medium', 'title' => 'Broken internal links', 'detail' => '12 links'],
                ['severity' => 'info', 'title' => 'Schema opportunities', 'detail' => '3 pages'],
            ],
            'lifecycle' => [
                ['label' => 'Domain', 'value' => 'atlasdental.example', 'provenance' => 'Detected'],
                ['label' => 'Domain status', 'value' => 'Active', 'provenance' => 'Detected'],
                ['label' => 'Domain expiry', 'value' => '21 Mar 2027', 'provenance' => 'Detected'],
                ['label' => 'Days remaining', 'value' => '221', 'provenance' => 'Detected'],
                ['label' => 'SSL expiry', 'value' => '09 Nov 2026', 'provenance' => 'Detected'],
                ['label' => 'Hosting provider', 'value' => 'DemoHost', 'provenance' => 'Manual'],
                ['label' => 'Hosting renewal', 'value' => '15 Sep 2026', 'provenance' => 'Manual'],
                ['label' => 'Uptime', 'value' => '99.97%', 'provenance' => 'Provider'],
                ['label' => 'Last backup', 'value' => 'Today', 'provenance' => 'Provider'],
                ['label' => 'DNS', 'value' => 'Healthy', 'provenance' => 'Detected'],
            ],
            'top_pages' => [
                ['path' => '/implant', 'sessions' => 6200, 'leads' => 88],
                ['path' => '/post-bariatric', 'sessions' => 4100, 'leads' => 54],
                ['path' => '/', 'sessions' => 3800, 'leads' => 22],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gbpOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'kpis' => [
                ['label' => 'Rating', 'value' => 4.8, 'format' => 'float', 'delta' => 0.0, 'tone' => 'good', 'family' => 'result'],
                ['label' => 'Reviews', 'value' => 1264, 'format' => 'int', 'delta' => null, 'tone' => 'neutral', 'family' => 'result'],
                ['label' => 'New Reviews', 'value' => (int) round(47 * $f['results_factor']), 'format' => 'int', 'delta' => 12.0, 'tone' => 'good', 'family' => 'result'],
                ['label' => 'Unanswered', 'value' => 12, 'format' => 'int', 'delta' => null, 'tone' => 'warn', 'family' => 'efficiency'],
                ['label' => 'Calls', 'value' => (int) round(418 * $f['results_factor']), 'format' => 'int', 'delta' => 5.0, 'tone' => 'good', 'family' => 'result'],
                ['label' => 'Website Clicks', 'value' => (int) round(723 * $f['results_factor']), 'format' => 'int', 'delta' => 3.0, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Directions', 'value' => (int) round(556 * $f['results_factor']), 'format' => 'int', 'delta' => 2.0, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Profile Views', 'value' => (int) round(12840 * $f['results_factor']), 'format' => 'int', 'delta' => 4.0, 'tone' => 'good', 'family' => 'delivery'],
            ],
            'map' => [
                'keyword' => 'implant ankara',
                'average_rank' => 5.4,
                'top3' => 42,
                'top10' => 81,
                'previous_average' => 4.8,
                'grid' => [
                    [3, 2, 1, 2, 4],
                    [5, 3, 1, 2, 5],
                    [7, 4, 2, 3, 7],
                    [12, 8, 5, 6, 12],
                    [18, 14, 9, 11, 18],
                ],
                'provenance' => 'External search intelligence',
            ],
            'reviews' => [
                'distribution' => [5 => 920, 4 => 240, 3 => 70, 2 => 22, 1 => 12],
                'themes_positive' => ['staff', 'cleanliness', 'communication'],
                'themes_negative' => ['waiting time', 'parking'],
                'ai_summary' => 'Patients praise staff and communication. Recurring friction: waiting time and parking near Çankaya clinic.',
                'recent' => [
                    ['rating' => 5, 'text' => 'Excellent implant consultation — clear plan.', 'when' => '2 days ago'],
                    ['rating' => 3, 'text' => 'Good care but waited 40 minutes.', 'when' => '5 days ago'],
                    ['rating' => 5, 'text' => 'Very clean clinic, friendly team.', 'when' => '1 week ago'],
                ],
            ],
            'queries' => [
                ['query' => 'implant ankara', 'visibility' => 'Strong', 'map_rank' => 5.4, 'trend' => 'down'],
                ['query' => 'diş kliniği ankara', 'visibility' => 'Medium', 'map_rank' => 8.1, 'trend' => 'flat'],
                ['query' => 'zirkonyum ankara', 'visibility' => 'Emerging', 'map_rank' => 11.0, 'trend' => 'up'],
                ['query' => 'çankaya diş kliniği', 'visibility' => 'Strong', 'map_rank' => 3.2, 'trend' => 'up'],
            ],
            'competitors' => [
                ['name' => 'Demo Smile Clinic', 'rating' => 4.6, 'reviews' => 890],
                ['name' => 'Ankara Implant Center (demo)', 'rating' => 4.7, 'reviews' => 1102],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function analyticsOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'kpis' => [
                ['label' => 'Users', 'value' => (int) round(18420 * $f['results_factor']), 'format' => 'int'],
                ['label' => 'New Users', 'value' => (int) round(12100 * $f['results_factor']), 'format' => 'int'],
                ['label' => 'Sessions', 'value' => (int) round(23840 * $f['results_factor']), 'format' => 'int'],
                ['label' => 'Engaged Sessions', 'value' => (int) round(14200 * $f['results_factor']), 'format' => 'int'],
                ['label' => 'Conversions', 'value' => (int) round(202 * $f['results_factor']), 'format' => 'int'],
            ],
            'sources' => [
                ['source' => 'Organic Search', 'sessions' => 9200],
                ['source' => 'Paid Social', 'sessions' => 6100],
                ['source' => 'Paid Search', 'sessions' => 4800],
                ['source' => 'Direct', 'sessions' => 2740],
            ],
            'devices' => ['mobile' => 68, 'desktop' => 28, 'tablet' => 4],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function searchConsoleOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'kpis' => [
                ['label' => 'Clicks', 'value' => (int) round(8420 * $f['results_factor']), 'format' => 'int'],
                ['label' => 'Impressions', 'value' => (int) round(186000 * $f['results_factor']), 'format' => 'int'],
                ['label' => 'CTR', 'value' => 4.5, 'format' => 'pct'],
                ['label' => 'Average Position', 'value' => 12.4, 'format' => 'float'],
            ],
            'queries' => [
                ['query' => 'implant ankara', 'clicks' => 920, 'impressions' => 18000, 'ctr' => 5.1, 'position' => 8.2, 'trend' => 'growing'],
                ['query' => 'diş implantı fiyat', 'clicks' => 410, 'impressions' => 22000, 'ctr' => 1.9, 'position' => 18.0, 'trend' => 'declining'],
                ['query' => 'atlas dental', 'clicks' => 380, 'impressions' => 2100, 'ctr' => 18.1, 'position' => 1.4, 'trend' => 'stable'],
            ],
            'pages' => [
                ['page' => '/implant', 'clicks' => 2100, 'impressions' => 42000],
                ['page' => '/', 'clicks' => 1600, 'impressions' => 38000],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findings(): array
    {
        return [
            [
                'id' => 'f-meta-cpl',
                'severity' => 'high',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'asset_id' => self::META_ASSET_ID,
                'title' => 'Meta CPL deteriorated',
                'evidence' => 'CPL ₺482 → ₺691 on Post Bariatric — Europe (14 days).',
                'detected' => '2 Jul 2026',
                'status' => 'open',
                'type' => 'performance',
            ],
            [
                'id' => 'f-web-lcp',
                'severity' => 'medium',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Website',
                'asset_id' => self::WEBSITE_ASSET_ID,
                'title' => 'Website mobile performance degraded',
                'evidence' => 'Mobile LCP 4.1s on /implant primary landing page.',
                'detected' => '3 Jul 2026',
                'status' => 'open',
                'type' => 'technical',
            ],
            [
                'id' => 'f-gads-waste',
                'severity' => 'high',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Ads',
                'asset_id' => self::GOOGLE_ADS_ASSET_ID,
                'title' => 'Google search-term waste',
                'evidence' => '₺12,240 on low-relevance queries (12.7% of spend).',
                'detected' => '1 Jul 2026',
                'status' => 'open',
                'type' => 'performance',
            ],
            [
                'id' => 'f-gbp-map',
                'severity' => 'medium',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Business Profile',
                'asset_id' => self::GBP_ASSET_ID,
                'title' => 'GBP local visibility declined',
                'evidence' => '"implant ankara" avg map rank 4.8 → 5.4; NE grid weakened.',
                'detected' => '4 Jul 2026',
                'status' => 'open',
                'type' => 'local',
            ],
            [
                'id' => 'f-host-renew',
                'severity' => 'info',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Hosting',
                'asset_id' => self::HOSTING_ASSET_ID,
                'title' => 'Hosting renewal upcoming',
                'evidence' => 'DemoHost renewal due 15 Sep 2026 (34 days).',
                'detected' => 'Today',
                'status' => 'open',
                'type' => 'lifecycle',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recommendationsSeed(): array
    {
        return [
            [
                'id' => 'r-replace-creative',
                'finding_id' => 'f-meta-cpl',
                'title' => 'Replace underperforming Meta creative PB-Video-03',
                'observation' => 'PB-Video-03 Link CTR fell 2.9% → 1.4% while CPL rose sharply.',
                'why' => 'Spend remains material; creative fatigue is the clearest efficiency lever.',
                'evidence' => 'Meta Ads daily facts · Creative PB-Video-03 · Campaign Post Bariatric — Europe',
                'action' => 'Produce and launch a replacement video creative; pause PB-Video-03 after launch.',
                'dependencies' => 'Creative production capacity this week.',
                'success' => 'CPL trending toward ≤ ₺550 over 14 days after launch.',
                'failure' => 'CPL remains ≥ ₺650 with CTR < 1.8% after 14 days.',
                'watch' => ['CPL', 'Link CTR', 'Frequency'],
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
            ],
            [
                'id' => 'r-fix-lcp',
                'finding_id' => 'f-web-lcp',
                'title' => 'Fix /implant mobile LCP',
                'observation' => 'Mobile LCP is 4.1s on the primary paid landing page.',
                'why' => 'Weak mobile experience may amplify paid-media inefficiency across Meta and Google.',
                'evidence' => 'Website technical scan · PageSpeed-style lab signal (demo)',
                'action' => 'Compress hero media, defer non-critical JS, verify LCP ≤ 2.5s on mobile.',
                'dependencies' => 'Dev capacity; hosting CDN settings.',
                'success' => 'Mobile LCP ≤ 2.5s on re-check.',
                'failure' => 'LCP remains > 3.5s after changes.',
                'watch' => ['LCP', 'Website conversion rate'],
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Website',
            ],
            [
                'id' => 'r-negatives',
                'finding_id' => 'f-gads-waste',
                'title' => 'Add negatives for low-relevance search terms',
                'observation' => '₺12,240 spent on low-relevance queries.',
                'why' => 'Immediate budget efficiency without expanding bids.',
                'evidence' => 'Google Ads search terms report (demo)',
                'action' => 'Add negatives for demo waste terms; review weekly.',
                'dependencies' => 'None',
                'success' => 'Waste share < 6% of spend in 14 days.',
                'failure' => 'Waste share remains > 10%.',
                'watch' => ['Waste spend %', 'CPA'],
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Ads',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function tasksSeed(): array
    {
        return [
            [
                'id' => 't-replace-creative',
                'title' => 'Replace PB-Video-03 creative',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'recommendation_id' => 'r-replace-creative',
                'owner' => 'Ayşe Yılmaz',
                'priority' => 'high',
                'due' => 'Friday',
                'status' => 'open',
                'description' => 'Produce and launch replacement creative; pause PB-Video-03 after launch.',
                'success_signal' => 'CPL trending toward ≤ ₺550 over 14 days.',
                'why' => [
                    'finding' => 'Meta CPL deteriorated on Post Bariatric — Europe',
                    'recommendation' => 'Replace underperforming Meta creative PB-Video-03',
                    'evidence' => 'CTR 2.9% → 1.4%; CPL ₺482 → ₺691',
                ],
                'outcome' => null,
            ],
            [
                'id' => 't-lcp',
                'title' => 'Improve /implant mobile LCP',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Website',
                'recommendation_id' => 'r-fix-lcp',
                'owner' => 'Can Demir',
                'priority' => 'medium',
                'due' => 'Next week',
                'status' => 'in_progress',
                'description' => 'Optimize hero media and JS on /implant.',
                'success_signal' => 'Mobile LCP ≤ 2.5s',
                'why' => [
                    'finding' => 'Website mobile performance degraded',
                    'recommendation' => 'Fix /implant mobile LCP',
                    'evidence' => 'LCP 4.1s',
                ],
                'outcome' => null,
            ],
            [
                'id' => 't-negatives',
                'title' => 'Add Google Ads negatives for waste terms',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Ads',
                'recommendation_id' => 'r-negatives',
                'owner' => 'Performance Specialist',
                'priority' => 'high',
                'due' => 'Tomorrow',
                'status' => 'open',
                'description' => 'Negate demo low-relevance queries from search terms list.',
                'success_signal' => 'Waste share < 6%',
                'why' => [
                    'finding' => 'Google search-term waste',
                    'recommendation' => 'Add negatives for low-relevance search terms',
                    'evidence' => '₺12,240 waste',
                ],
                'outcome' => null,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function decisionTimeline(): array
    {
        return [
            ['date' => 'Jul 2', 'event' => 'Finding detected', 'detail' => 'Meta CPL deterioration on Post Bariatric — Europe'],
            ['date' => 'Jul 3', 'event' => 'Recommendation approved', 'detail' => 'Creative replacement recommended'],
            ['date' => 'Jul 4', 'event' => 'Task created', 'detail' => 'Assigned to Ayşe Yılmaz'],
            ['date' => 'Jul 7', 'event' => 'External work noted', 'detail' => 'New creative launched externally (manual note)'],
            ['date' => 'Jul 21', 'event' => 'Follow-up evaluated', 'detail' => 'New data collected for 14-day window'],
            ['date' => 'Jul 21', 'event' => 'Associated improvement observed', 'detail' => 'CPL ₺691 → ₺548 over follow-up period (not claiming causality)'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function brandAttention(): array
    {
        return [
            [
                'severity' => 'high',
                'asset' => 'Meta Ads',
                'issue' => 'Lead cost increased 31% in the last 14 days.',
                'evidence' => 'Main contributor: Post Bariatric — Europe campaign.',
                'why' => 'Largest paid efficiency issue on the brand.',
                'source' => 'Connected provider',
                'route' => 'demo.meta.overview',
            ],
            [
                'severity' => 'medium',
                'asset' => 'Website',
                'issue' => 'Mobile LCP deteriorated to 4.1s on the primary landing page.',
                'evidence' => '/implant technical check',
                'why' => 'May amplify paid-media inefficiency.',
                'source' => 'Public + Detected',
                'route' => 'demo.website',
            ],
            [
                'severity' => 'medium',
                'asset' => 'Google Business Profile',
                'issue' => '"implant ankara" visibility weakened in the north-east map grid.',
                'evidence' => 'Avg map rank 4.8 → 5.4',
                'why' => 'Local demand keyword for implants.',
                'source' => 'External search intelligence',
                'route' => 'demo.gbp',
            ],
            [
                'severity' => 'info',
                'asset' => 'Domain',
                'issue' => 'Renewal in 221 days.',
                'evidence' => 'Expiry 21 Mar 2027',
                'why' => 'Lifecycle awareness — no immediate action.',
                'source' => 'Detected',
                'route' => 'demo.website',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function integrations(): array
    {
        return [
            [
                'id' => 'meta',
                'name' => 'Meta',
                'status' => 'connected',
                'summary' => 'Businesses 5 · Ad Accounts 31 · History import available',
                'last_sync' => '2 hours ago',
                'problems' => 1,
                'route' => 'demo.integrations.meta',
            ],
            [
                'id' => 'google',
                'name' => 'Google',
                'status' => 'connected',
                'summary' => 'Ads · Analytics · Search Console · Business Profile linked (demo)',
                'last_sync' => 'Today',
                'problems' => 0,
                'route' => 'demo.integrations',
            ],
            [
                'id' => 'dataforseo',
                'name' => 'DataForSEO / Search Intelligence',
                'status' => 'configured',
                'summary' => 'Map grid + SERP intelligence available for demo',
                'last_sync' => 'Yesterday',
                'problems' => 0,
                'route' => 'demo.integrations',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function metaImportAccounts(): array
    {
        return [
            [
                'id' => 'acc-atlas',
                'name' => 'Atlas Dental — Meta',
                'business' => 'Atlas Health BM',
                'status' => 'ready',
                'coverage' => '12 Jul 2023 → Today',
                'campaigns' => 73,
                'adsets' => 491,
                'ads' => 2814,
                'creatives' => 812,
                'daily_facts' => 248391,
                'phase' => 'Ready',
            ],
            [
                'id' => 'acc-panorama',
                'name' => 'Panorama Demo',
                'business' => 'Atlas Health BM',
                'status' => 'ready',
                'coverage' => '01 Jan 2024 → Today',
                'campaigns' => 22,
                'adsets' => 80,
                'ads' => 410,
                'creatives' => 120,
                'daily_facts' => 40210,
                'phase' => 'Ready',
            ],
            [
                'id' => 'acc-horizon',
                'name' => 'Horizon Clinic',
                'business' => 'Partner BM',
                'status' => 'importing',
                'coverage' => '—',
                'campaigns' => 18,
                'adsets' => 40,
                'ads' => 120,
                'creatives' => 55,
                'daily_facts' => 12040,
                'phase' => 'Daily Insights · chunk 28 / 51',
                'chunks_done' => 28,
                'chunks_total' => 51,
            ],
            [
                'id' => 'acc-nova',
                'name' => 'Nova Health',
                'business' => 'Partner BM',
                'status' => 'waiting',
                'coverage' => '—',
                'campaigns' => null,
                'adsets' => null,
                'ads' => null,
                'creatives' => null,
                'daily_facts' => null,
                'phase' => 'Waiting for Meta Insights report',
            ],
            [
                'id' => 'acc-12',
                'name' => 'Demo Brand 12',
                'business' => 'Demo BM',
                'status' => 'queued',
                'coverage' => '—',
                'phase' => 'Queued',
            ],
            [
                'id' => 'acc-13',
                'name' => 'Demo Brand 13',
                'business' => 'Demo BM',
                'status' => 'queued',
                'coverage' => '—',
                'phase' => 'Queued',
            ],
            [
                'id' => 'acc-sample',
                'name' => 'Sample Account',
                'business' => 'Restricted BM',
                'status' => 'needs_attention',
                'coverage' => '—',
                'phase' => 'Permission denied',
                'error' => 'Permission denied — ads_read missing for this account (demo).',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activitySeed(): array
    {
        return [
            ['id' => 'a1', 'title' => 'Meta data import', 'status' => 'running', 'detail' => '11 / 31 accounts ready', 'when' => 'Now'],
            ['id' => 'a2', 'title' => 'Website technical scan', 'status' => 'completed', 'detail' => 'atlasdental.example', 'when' => 'Today'],
            ['id' => 'a3', 'title' => 'GBP map grid refresh', 'status' => 'completed', 'detail' => 'implant ankara', 'when' => 'Yesterday'],
            ['id' => 'a4', 'title' => 'Brand AI analysis', 'status' => 'completed', 'detail' => 'Atlas Dental Ankara', 'when' => 'Yesterday'],
            ['id' => 'a5', 'title' => 'Google Ads refresh', 'status' => 'partial', 'detail' => 'Search terms OK · one asset skipped', 'when' => '2 days ago'],
            ['id' => 'a6', 'title' => 'Public brand research', 'status' => 'queued', 'detail' => 'Waiting', 'when' => '—'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function aiBrandBrief(): array
    {
        return [
            'headline' => 'Brand analysis · Atlas Dental Ankara',
            'points' => [
                'Meta Ads is the largest immediate efficiency issue.',
                'Website mobile performance may amplify paid-media inefficiency.',
                'Google Ads remains comparatively stable.',
                'GBP review velocity is healthy but north-east local visibility for “implant ankara” weakened.',
            ],
            'evidence_links' => [
                ['label' => 'Meta Ads', 'route' => 'demo.meta.overview'],
                ['label' => 'Website', 'route' => 'demo.website'],
                ['label' => 'GBP', 'route' => 'demo.gbp'],
            ],
            'priorities' => [
                'Replace underperforming Meta creative.',
                'Fix landing-page mobile LCP.',
                'Review local relevance for “implant ankara”.',
            ],
            'disclaimer' => 'Demo guidance — no live model call. AI does not write to external systems.',
        ];
    }

    /**
     * @return array{labels: list<string>, values: list<float>}
     */
    public static function trendSeries(string $metric, int $points, float $min, float $max): array
    {
        $labels = [];
        $values = [];
        $seed = crc32($metric.$points);
        for ($i = 0; $i < max(7, $points); $i++) {
            $labels[] = now()->subDays(max(7, $points) - $i - 1)->format('M j');
            $wave = sin(($i + ($seed % 7)) / 2.3);
            $values[] = round($min + (($max - $min) * (0.45 + 0.45 * $wave)), 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
