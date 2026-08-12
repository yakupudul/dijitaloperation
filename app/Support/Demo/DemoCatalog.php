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
     * @return array{role: string, role_label: string}
     */
    public static function assetTaxonomy(string $type): array
    {
        return match ($type) {
            'website', 'meta_ads', 'google_ads', 'gbp' => [
                'role' => 'primary_managed',
                'role_label' => 'Primary managed asset',
            ],
            'ga4', 'gsc' => [
                'role' => 'connected_source',
                'role_label' => 'Connected data source',
            ],
            'domain', 'hosting' => [
                'role' => 'infrastructure',
                'role_label' => 'Infrastructure / lifecycle',
            ],
            default => [
                'role' => 'other',
                'role_label' => 'Other',
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function assets(): array
    {
        $rows = [
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

        return array_map(static function (array $asset): array {
            return array_merge($asset, self::assetTaxonomy((string) $asset['type']));
        }, $rows);
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
     * @return array{spend_factor: float, results_factor: float, efficiency_factor: float, label: string, narrative: string}
     */
    public static function periodFactors(string $preset): array
    {
        return match ($preset) {
            'last_7' => [
                'spend_factor' => 0.22,
                'results_factor' => 0.18,
                'efficiency_factor' => 1.18,
                'label' => 'Last 7 days',
                'narrative' => 'Short window — CPL elevated after weekend auction pressure.',
            ],
            'last_14' => [
                'spend_factor' => 0.48,
                'results_factor' => 0.42,
                'efficiency_factor' => 1.12,
                'label' => 'Last 14 days',
                'narrative' => 'Creative fatigue visible; Meta CPL still above May baseline.',
            ],
            'last_28' => [
                'spend_factor' => 1.00,
                'results_factor' => 1.00,
                'efficiency_factor' => 1.00,
                'label' => 'Last 28 days',
                'narrative' => 'July recovery underway after May deterioration; Meta still the efficiency bottleneck.',
            ],
            'last_30' => [
                'spend_factor' => 1.08,
                'results_factor' => 1.02,
                'efficiency_factor' => 1.04,
                'label' => 'Last 30 days',
                'narrative' => 'Slightly longer window softens daily volatility; waste share still material.',
            ],
            'this_month' => [
                'spend_factor' => 0.55,
                'results_factor' => 0.58,
                'efficiency_factor' => 0.94,
                'label' => 'This month',
                'narrative' => 'Month-to-date recovery: results improving faster than spend.',
            ],
            'last_month' => [
                'spend_factor' => 1.22,
                'results_factor' => 0.92,
                'efficiency_factor' => 1.28,
                'label' => 'Last month',
                'narrative' => 'May-style deterioration window — spend up, leads down, CPL peaked.',
            ],
            'custom' => [
                'spend_factor' => 0.86,
                'results_factor' => 0.91,
                'efficiency_factor' => 0.97,
                'label' => 'Custom range',
                'narrative' => 'Custom range blended toward July recovery narrative.',
            ],
            default => [
                'spend_factor' => 1.00,
                'results_factor' => 1.00,
                'efficiency_factor' => 1.00,
                'label' => 'Last 28 days',
                'narrative' => 'July recovery underway after May deterioration; Meta still the efficiency bottleneck.',
            ],
        };
    }

    /**
     * Deterministic period-over-period delta (%) for demo KPI chips.
     */
    public static function compareDelta(string $preset, string $metricKey): float
    {
        $f = self::periodFactors($preset);
        $seed = (crc32($preset.'|'.$metricKey) % 900) / 100;
        $base = match ($metricKey) {
            'spend' => round(($f['spend_factor'] - 1) * 100 + 4.2, 1),
            'leads', 'conversions', 'results', 'messaging' => round(($f['results_factor'] - 1) * 100 - 2.1, 1),
            'cpl', 'cpa', 'cost_result' => round(($f['efficiency_factor'] - 1) * 100 + 8.4, 1),
            'ctr', 'link_ctr' => round((1 - $f['efficiency_factor']) * 40 - 3.5, 1),
            'cpm', 'cpc' => round(($f['efficiency_factor'] - 1) * 30 - 1.2, 1),
            'reach', 'impressions', 'sessions', 'clicks' => round(($f['results_factor'] - 1) * 80 + 2.0, 1),
            'frequency' => round(($f['efficiency_factor'] - 1) * 25 + 1.5, 1),
            'waste_share' => round(($f['efficiency_factor'] - 1) * 50 + 3.0, 1),
            default => round(($f['results_factor'] - $f['spend_factor']) * 40 + $seed - 4.0, 1),
        };

        return round($base + (($seed - 4.5) * 0.35), 1);
    }

    public static function seasonalityNote(string $preset): ?string
    {
        return match ($preset) {
            'last_7' => 'Seasonality: late-week demand soft; weekend messaging holds while lead CPL stays elevated.',
            'last_14' => 'Seasonality: mid-July recovery incomplete — May deterioration still visible in trailing CPL.',
            'last_28' => 'Seasonality: May deterioration → June plateau → July recovery. Meta CPL remains above spring baseline.',
            'last_30' => 'Seasonality: 30-day blend includes the May efficiency trough and early July rebound.',
            'this_month' => 'Seasonality: current month tracking toward recovery; spend discipline improving vs last month.',
            'last_month' => 'Seasonality: last month mirrors the May deterioration pattern — higher spend, weaker lead efficiency.',
            'custom' => 'Seasonality: custom window interpolated toward the July recovery narrative.',
            default => 'Seasonality: May deterioration, July recovery — efficiency still uneven across Meta campaigns.',
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
        $cpl = $leads > 0 ? round(($spend / $leads) * $f['efficiency_factor'], 2) : 0;

        return [
            'period_label' => $f['label'],
            'seasonality' => self::seasonalityNote($preset),
            'kpis' => [
                ['key' => 'spend', 'label' => 'Spend', 'value' => $spend, 'format' => 'try', 'delta' => self::compareDelta($preset, 'spend'), 'tone' => 'neutral', 'family' => 'spend'],
                ['key' => 'leads', 'label' => 'Leads', 'value' => $leads, 'format' => 'int', 'delta' => self::compareDelta($preset, 'leads'), 'tone' => 'bad', 'family' => 'result'],
                ['key' => 'cpl', 'label' => 'Cost / Lead', 'value' => $cpl, 'format' => 'try', 'delta' => self::compareDelta($preset, 'cpl'), 'tone' => 'bad', 'family' => 'efficiency'],
                ['key' => 'messaging', 'label' => 'Messaging Conversations', 'value' => $msg, 'format' => 'int', 'delta' => self::compareDelta($preset, 'messaging'), 'tone' => 'good', 'family' => 'result'],
                ['key' => 'reach', 'label' => 'Reach', 'value' => (int) round(482400 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'reach'), 'tone' => 'neutral', 'family' => 'delivery'],
                ['key' => 'frequency', 'label' => 'Frequency', 'value' => round(2.21 * $f['efficiency_factor'], 2), 'format' => 'float', 'delta' => self::compareDelta($preset, 'frequency'), 'tone' => 'warn', 'family' => 'delivery'],
                ['key' => 'ctr', 'label' => 'Link CTR', 'value' => round(2.84 / max(0.85, $f['efficiency_factor']), 2), 'format' => 'pct', 'delta' => self::compareDelta($preset, 'ctr'), 'tone' => 'bad', 'family' => 'delivery'],
                ['key' => 'cpm', 'label' => 'CPM', 'value' => round(176.40 * $f['efficiency_factor'], 2), 'format' => 'try', 'delta' => self::compareDelta($preset, 'cpm'), 'tone' => 'good', 'family' => 'efficiency'],
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
            'breakdowns' => self::metaBreakdowns($preset),
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
                'cost_result' => round(691 * $f['efficiency_factor']),
                'reach' => (int) round(92000 * $f['results_factor']),
                'frequency' => round(2.4 * $f['efficiency_factor'], 1),
                'ctr' => round(1.4 / max(0.85, $f['efficiency_factor']), 2),
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
                'cost_result' => round(442 * $f['efficiency_factor']),
                'reach' => (int) round(140000 * $f['results_factor']),
                'frequency' => round(2.0 * $f['efficiency_factor'], 1),
                'ctr' => round(3.1 / max(0.85, $f['efficiency_factor']), 2),
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
                'cost_result' => round(45 * $f['efficiency_factor']),
                'reach' => (int) round(110000 * $f['results_factor']),
                'frequency' => round(1.9 * $f['efficiency_factor'], 1),
                'ctr' => round(2.9 / max(0.85, $f['efficiency_factor']), 2),
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
                'cost_result' => round(267 * $f['efficiency_factor']),
                'reach' => (int) round(48000 * $f['results_factor']),
                'frequency' => round(3.1 * $f['efficiency_factor'], 1),
                'ctr' => round(2.2 / max(0.85, $f['efficiency_factor']), 2),
                'attention' => 'medium',
                'trend' => [8, 7, 6, 5, 5, 4, 4],
            ],
            [
                'id' => 'camp-awareness',
                'name' => 'Brand Awareness — Ankara',
                'status' => 'ACTIVE',
                'objective' => 'OUTCOME_AWARENESS',
                'spend' => round(12400 * $f['spend_factor']),
                'results' => (int) round(210000 * $f['results_factor']),
                'result_label' => 'Reach',
                'cost_result' => round(18 * $f['efficiency_factor']),
                'reach' => (int) round(210000 * $f['results_factor']),
                'frequency' => round(1.4 * $f['efficiency_factor'], 1),
                'ctr' => round(1.1 / max(0.85, $f['efficiency_factor']), 2),
                'attention' => null,
                'trend' => [3, 4, 4, 5, 5, 6, 6],
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
                'cost_result' => round(981 * $f['efficiency_factor']),
                'ctr' => round(1.4 / max(0.85, $f['efficiency_factor']), 2),
                'frequency' => round(2.8 * $f['efficiency_factor'], 1),
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
                'cost_result' => round(420 * $f['efficiency_factor']),
                'ctr' => round(3.4 / max(0.85, $f['efficiency_factor']), 2),
                'frequency' => round(1.9 * $f['efficiency_factor'], 1),
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
                'cost_result' => round(45 * $f['efficiency_factor']),
                'ctr' => round(3.0 / max(0.85, $f['efficiency_factor']), 2),
                'frequency' => round(1.7 * $f['efficiency_factor'], 1),
                'attention' => null,
                'headline' => 'Message us on WhatsApp',
                'copy' => 'Same-day answers from Atlas Dental coordinators.',
                'cta' => 'Send message',
                'destination' => 'https://atlasdental.example',
                'preview' => 'carousel',
            ],
            [
                'id' => 'cr-pb-static-07',
                'name' => 'PB-Static-07',
                'format' => 'Image',
                'campaign' => 'Post Bariatric — Europe',
                'spend' => round(14800 * $f['spend_factor']),
                'result' => (int) round(28 * $f['results_factor']),
                'result_label' => 'Leads',
                'cost_result' => round(529 * $f['efficiency_factor']),
                'ctr' => round(2.1 / max(0.85, $f['efficiency_factor']), 2),
                'frequency' => round(2.2 * $f['efficiency_factor'], 1),
                'attention' => 'medium',
                'headline' => 'Post-bariatric contouring',
                'copy' => 'Consult with Atlas Dental Ankara specialists.',
                'cta' => 'Book now',
                'destination' => 'https://atlasdental.example/post-bariatric',
                'preview' => 'image',
            ],
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function metaBreakdowns(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        $scale = static function (float $spend, float $results) use ($f): array {
            $s = round($spend * $f['spend_factor']);
            $r = (int) round($results * $f['results_factor']);

            return [
                'spend' => $s,
                'results' => $r,
                'efficiency' => $r > 0 ? round(($s / $r) * $f['efficiency_factor'], 2) : null,
            ];
        };

        return [
            'placement' => [
                array_merge(['dimension' => 'Facebook Feed'], $scale(72000, 118)),
                array_merge(['dimension' => 'Instagram Feed'], $scale(54000, 96)),
                array_merge(['dimension' => 'Instagram Stories'], $scale(28000, 52)),
                array_merge(['dimension' => 'Audience Network'], $scale(18200, 22)),
                array_merge(['dimension' => 'Reels'], $scale(12120, 24)),
            ],
            'device' => [
                array_merge(['dimension' => 'Mobile'], $scale(148000, 248)),
                array_merge(['dimension' => 'Desktop'], $scale(28400, 48)),
                array_merge(['dimension' => 'Tablet'], $scale(7920, 16)),
            ],
            'age' => [
                array_merge(['dimension' => '18–24'], $scale(18400, 28)),
                array_merge(['dimension' => '25–34'], $scale(72000, 132)),
                array_merge(['dimension' => '35–44'], $scale(58000, 98)),
                array_merge(['dimension' => '45–54'], $scale(26800, 42)),
                array_merge(['dimension' => '55+'], $scale(9120, 12)),
            ],
            'gender' => [
                array_merge(['dimension' => 'Female'], $scale(112400, 198)),
                array_merge(['dimension' => 'Male'], $scale(62800, 96)),
                array_merge(['dimension' => 'Unknown'], $scale(9120, 18)),
            ],
            'region' => [
                array_merge(['dimension' => 'Ankara'], $scale(62000, 128)),
                array_merge(['dimension' => 'İstanbul'], $scale(48000, 72)),
                array_merge(['dimension' => 'İzmir'], $scale(22000, 38)),
                array_merge(['dimension' => 'Europe (ex-TR)'], $scale(38400, 48)),
                array_merge(['dimension' => 'Other TR'], $scale(13920, 26)),
            ],
        ];
    }

    /**
     * Flat list of ad sets across campaigns.
     *
     * @return list<array<string, mixed>>
     */
    public static function metaAdSetsList(string $preset = 'last_28'): array
    {
        $rows = [];
        foreach (self::metaCampaigns($preset) as $campaign) {
            foreach (self::metaAdSets($campaign['id'], $preset) as $adSet) {
                $rows[] = array_merge($adSet, [
                    'campaign_id' => $campaign['id'],
                    'campaign_name' => $campaign['name'],
                    'objective' => $campaign['objective'],
                    'cost_result' => $adSet['results'] > 0
                        ? round($adSet['spend'] / $adSet['results'], 2)
                        : null,
                ]);
            }
        }

        return $rows;
    }

    /**
     * Flat ads with creative refs.
     *
     * @return list<array<string, mixed>>
     */
    public static function metaAdsList(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        $creatives = self::metaCreatives($preset);
        $ads = [];

        foreach ($creatives as $index => $creative) {
            $campaignId = 'camp-pb-eu';
            foreach (self::metaCampaigns($preset) as $campaign) {
                if ($campaign['name'] === $creative['campaign']) {
                    $campaignId = $campaign['id'];
                    break;
                }
            }
            $adSets = self::metaAdSets($campaignId, $preset);
            $adSet = $adSets[$index % count($adSets)];

            $ads[] = [
                'id' => 'ad-'.str_replace('cr-', '', $creative['id']),
                'name' => $creative['name'].' · Ad',
                'status' => $index === 0 ? 'ACTIVE' : ($index === 3 ? 'PAUSED' : 'ACTIVE'),
                'campaign_id' => $campaignId,
                'campaign_name' => $creative['campaign'],
                'adset_id' => $adSet['id'],
                'adset_name' => $adSet['name'],
                'creative_id' => $creative['id'],
                'creative_name' => $creative['name'],
                'format' => $creative['format'],
                'spend' => $creative['spend'],
                'results' => $creative['result'],
                'result_label' => $creative['result_label'],
                'cost_result' => $creative['cost_result'],
                'ctr' => $creative['ctr'],
                'attention' => $creative['attention'],
                'preview' => $creative['preview'],
            ];
        }

        // Extra ad sharing a creative to show multi-ad creative reuse.
        $ads[] = [
            'id' => 'ad-pb-video-03-b',
            'name' => 'PB-Video-03 · Variant B',
            'status' => 'ACTIVE',
            'campaign_id' => 'camp-pb-eu',
            'campaign_name' => 'Post Bariatric — Europe',
            'adset_id' => 'camp-pb-eu-as2',
            'adset_name' => 'Lookalike purchasers',
            'creative_id' => 'cr-pb-video-03',
            'creative_name' => 'PB-Video-03',
            'format' => 'Video',
            'spend' => round(9800 * $f['spend_factor']),
            'results' => (int) round(9 * $f['results_factor']),
            'result_label' => 'Leads',
            'cost_result' => round(1089 * $f['efficiency_factor']),
            'ctr' => round(1.1 / max(0.85, $f['efficiency_factor']), 2),
            'attention' => 'high',
            'preview' => 'video',
        ];

        return $ads;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function filterMetaCampaigns(string $preset, ?string $status = null, ?string $objective = null): array
    {
        $rows = self::metaCampaigns($preset);

        if ($status !== null && $status !== '' && strtolower($status) !== 'all') {
            $needle = strtoupper($status);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => strtoupper((string) $row['status']) === $needle
            ));
        }

        if ($objective !== null && $objective !== '' && strtolower($objective) !== 'all') {
            $needle = strtoupper($objective);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => strtoupper((string) $row['objective']) === $needle
            ));
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function googleAdsOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        $spend = round(96480 * $f['spend_factor']);
        $conv = (int) round(126 * $f['results_factor']);
        $cpa = $conv > 0 ? round(($spend / $conv) * $f['efficiency_factor'], 2) : 0;

        return [
            'period_label' => $f['label'],
            'seasonality' => self::seasonalityNote($preset),
            'kpis' => [
                ['label' => 'Spend', 'value' => $spend, 'format' => 'try', 'delta' => self::compareDelta($preset, 'spend'), 'tone' => 'neutral', 'family' => 'spend'],
                ['label' => 'Conversions', 'value' => $conv, 'format' => 'int', 'delta' => self::compareDelta($preset, 'conversions'), 'tone' => 'good', 'family' => 'result'],
                ['label' => 'CPA', 'value' => $cpa, 'format' => 'try', 'delta' => self::compareDelta($preset, 'cpa'), 'tone' => 'neutral', 'family' => 'efficiency'],
                ['label' => 'Clicks', 'value' => (int) round(9842 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'clicks'), 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'CTR', 'value' => round(8.20 / max(0.85, $f['efficiency_factor']), 2), 'format' => 'pct', 'delta' => self::compareDelta($preset, 'ctr'), 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Avg CPC', 'value' => round(9.80 * $f['efficiency_factor'], 2), 'format' => 'try', 'delta' => self::compareDelta($preset, 'cpc'), 'tone' => 'good', 'family' => 'efficiency'],
                ['label' => 'Conversion Rate', 'value' => round(1.28 * $f['results_factor'] / max(0.5, $f['spend_factor']), 2), 'format' => 'pct', 'delta' => self::compareDelta($preset, 'conv_rate'), 'tone' => 'warn', 'family' => 'efficiency'],
                ['label' => 'Impression Share', 'value' => (int) round(68 * min(1.15, $f['results_factor'] / max(0.5, $f['spend_factor']))), 'format' => 'pct', 'delta' => self::compareDelta($preset, 'impr_share'), 'tone' => 'neutral', 'family' => 'delivery'],
            ],
            'attention' => [
                [
                    'severity' => 'high',
                    'title' => 'Search Term Waste',
                    'body' => '₺'.number_format((int) round(12240 * $f['spend_factor'])).' spent on low-relevance search queries ('.round(12.7 * $f['efficiency_factor'], 1).'% of total spend).',
                    'source' => 'Google Ads · Connected provider',
                ],
                [
                    'severity' => 'medium',
                    'title' => 'Landing Page',
                    'body' => 'Campaign “Implant Search” sends 41% of paid traffic to a page with weak mobile performance.',
                    'source' => 'Google Ads + Website',
                ],
            ],
            'search_terms' => self::googleSearchTerms($preset),
            'campaigns' => [
                ['name' => 'Implant Search', 'status' => 'ENABLED', 'spend' => round(48200 * $f['spend_factor']), 'conv' => (int) round(62 * $f['results_factor']), 'cpa' => round(777 * $f['efficiency_factor'])],
                ['name' => 'Brand', 'status' => 'ENABLED', 'spend' => round(18400 * $f['spend_factor']), 'conv' => (int) round(40 * $f['results_factor']), 'cpa' => round(460 * $f['efficiency_factor'])],
                ['name' => 'Competitor', 'status' => 'PAUSED', 'spend' => round(9800 * $f['spend_factor']), 'conv' => (int) round(8 * $f['results_factor']), 'cpa' => round(1225 * $f['efficiency_factor'])],
                ['name' => 'Local Services', 'status' => 'ENABLED', 'spend' => round(11200 * $f['spend_factor']), 'conv' => (int) round(14 * $f['results_factor']), 'cpa' => round(800 * $f['efficiency_factor'])],
            ],
            'ad_groups' => self::googleAdGroups($preset),
            'keywords' => self::googleKeywords($preset),
            'ads_assets' => self::googleAdsAssets($preset),
            'conversions' => self::googleConversions($preset),
            'landing_pages' => [
                ['url' => '/implant', 'sessions' => (int) round(4200 * $f['results_factor']), 'conv_rate' => round(1.9 / max(0.9, $f['efficiency_factor']), 2), 'mobile_lcp' => '4.1s', 'note' => 'Weak mobile'],
                ['url' => '/', 'sessions' => (int) round(2100 * $f['results_factor']), 'conv_rate' => 0.8, 'mobile_lcp' => '2.4s', 'note' => 'OK'],
                ['url' => '/post-bariatric', 'sessions' => (int) round(1680 * $f['results_factor']), 'conv_rate' => 1.4, 'mobile_lcp' => '3.2s', 'note' => 'Watch'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function googleSearchTerms(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        $rows = [
            ['term' => 'ucuz diş implantı', 'campaign' => 'Implant Search', 'spend' => 4200, 'clicks' => 310, 'conversions' => 1, 'cpa' => 4200, 'relevance' => 'low', 'classification' => 'Negative candidate', 'action' => 'Negative candidate'],
            ['term' => 'implant ankara fiyat', 'campaign' => 'Implant Search', 'spend' => 3800, 'clicks' => 220, 'conversions' => 9, 'cpa' => 422, 'relevance' => 'high', 'classification' => 'Keep', 'action' => 'Keep'],
            ['term' => 'dişçi oyunu', 'campaign' => 'Brand Broad', 'spend' => 2100, 'clicks' => 540, 'conversions' => 0, 'cpa' => null, 'relevance' => 'low', 'classification' => 'Irrelevant', 'action' => 'Negative candidate'],
            ['term' => 'atlas dental ankara', 'campaign' => 'Brand', 'spend' => 980, 'clicks' => 140, 'conversions' => 18, 'cpa' => 54, 'relevance' => 'high', 'classification' => 'Brand', 'action' => 'Keep'],
            ['term' => 'zirkonyum diş ankara', 'campaign' => 'Implant Search', 'spend' => 1160, 'clicks' => 95, 'conversions' => 4, 'cpa' => 290, 'relevance' => 'medium', 'classification' => 'Review', 'action' => 'Review'],
            ['term' => 'demo smile clinic implant', 'campaign' => 'Competitor', 'spend' => 1540, 'clicks' => 88, 'conversions' => 2, 'cpa' => 770, 'relevance' => 'medium', 'classification' => 'Competitor', 'action' => 'Review'],
            ['term' => 'implant eğitimi online', 'campaign' => 'Implant Search', 'spend' => 890, 'clicks' => 120, 'conversions' => 0, 'cpa' => null, 'relevance' => 'low', 'classification' => 'Irrelevant', 'action' => 'Negative candidate'],
            ['term' => 'çankaya diş kliniği', 'campaign' => 'Local Services', 'spend' => 1320, 'clicks' => 110, 'conversions' => 7, 'cpa' => 189, 'relevance' => 'high', 'classification' => 'Keep', 'action' => 'Keep'],
            ['term' => 'anadolu sağlık implant', 'campaign' => 'Competitor', 'spend' => 760, 'clicks' => 54, 'conversions' => 1, 'cpa' => 760, 'relevance' => 'medium', 'classification' => 'Competitor', 'action' => 'Review'],
            ['term' => 'atlas dental randevu', 'campaign' => 'Brand', 'spend' => 420, 'clicks' => 68, 'conversions' => 11, 'cpa' => 38, 'relevance' => 'high', 'classification' => 'Brand', 'action' => 'Keep'],
            ['term' => 'implant bakımı nasıl yapılır', 'campaign' => 'Implant Search', 'spend' => 640, 'clicks' => 95, 'conversions' => 1, 'cpa' => 640, 'relevance' => 'medium', 'classification' => 'Review', 'action' => 'Review'],
            ['term' => 'ücretsiz diş muayene oyunu', 'campaign' => 'Brand Broad', 'spend' => 510, 'clicks' => 210, 'conversions' => 0, 'cpa' => null, 'relevance' => 'low', 'classification' => 'Negative candidate', 'action' => 'Negative candidate'],
        ];

        return array_map(static function (array $row) use ($f): array {
            $spend = round($row['spend'] * $f['spend_factor']);
            $clicks = (int) round($row['clicks'] * $f['results_factor']);
            $conversions = (int) round($row['conversions'] * $f['results_factor']);

            return array_merge($row, [
                'spend' => $spend,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'cpa' => $conversions > 0 ? round($spend / $conversions) : null,
            ]);
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function filterSearchTerms(string $preset, ?string $classification = null): array
    {
        $rows = self::googleSearchTerms($preset);

        if ($classification === null || $classification === '' || strtolower($classification) === 'all') {
            return $rows;
        }

        $needle = strtolower($classification);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => strtolower((string) ($row['classification'] ?? $row['action'] ?? '')) === $needle
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function googleAdGroups(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            [
                'id' => 'ag-implant-core',
                'name' => 'Implant — Core Intent',
                'campaign' => 'Implant Search',
                'status' => 'ENABLED',
                'spend' => round(28600 * $f['spend_factor']),
                'clicks' => (int) round(2100 * $f['results_factor']),
                'conv' => (int) round(38 * $f['results_factor']),
                'cpa' => round(753 * $f['efficiency_factor']),
                'ctr' => round(9.4 / max(0.85, $f['efficiency_factor']), 2),
            ],
            [
                'id' => 'ag-implant-price',
                'name' => 'Implant — Price Intent',
                'campaign' => 'Implant Search',
                'status' => 'ENABLED',
                'spend' => round(19600 * $f['spend_factor']),
                'clicks' => (int) round(1680 * $f['results_factor']),
                'conv' => (int) round(24 * $f['results_factor']),
                'cpa' => round(817 * $f['efficiency_factor']),
                'ctr' => round(7.1 / max(0.85, $f['efficiency_factor']), 2),
            ],
            [
                'id' => 'ag-brand-exact',
                'name' => 'Brand Exact',
                'campaign' => 'Brand',
                'status' => 'ENABLED',
                'spend' => round(12400 * $f['spend_factor']),
                'clicks' => (int) round(980 * $f['results_factor']),
                'conv' => (int) round(32 * $f['results_factor']),
                'cpa' => round(388 * $f['efficiency_factor']),
                'ctr' => round(18.2 / max(0.85, $f['efficiency_factor']), 2),
            ],
            [
                'id' => 'ag-competitor',
                'name' => 'Competitor Names',
                'campaign' => 'Competitor',
                'status' => 'PAUSED',
                'spend' => round(9800 * $f['spend_factor']),
                'clicks' => (int) round(540 * $f['results_factor']),
                'conv' => (int) round(8 * $f['results_factor']),
                'cpa' => round(1225 * $f['efficiency_factor']),
                'ctr' => round(4.8 / max(0.85, $f['efficiency_factor']), 2),
            ],
            [
                'id' => 'ag-local',
                'name' => 'Çankaya Local',
                'campaign' => 'Local Services',
                'status' => 'ENABLED',
                'spend' => round(11200 * $f['spend_factor']),
                'clicks' => (int) round(720 * $f['results_factor']),
                'conv' => (int) round(14 * $f['results_factor']),
                'cpa' => round(800 * $f['efficiency_factor']),
                'ctr' => round(6.9 / max(0.85, $f['efficiency_factor']), 2),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function googleKeywords(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            ['keyword' => 'implant ankara', 'match' => 'Phrase', 'ad_group' => 'Implant — Core Intent', 'spend' => round(9200 * $f['spend_factor']), 'clicks' => (int) round(640 * $f['results_factor']), 'conv' => (int) round(22 * $f['results_factor']), 'qs' => 8, 'status' => 'ENABLED'],
            ['keyword' => 'diş kliniği çankaya', 'match' => 'Exact', 'ad_group' => 'Çankaya Local', 'spend' => round(4100 * $f['spend_factor']), 'clicks' => (int) round(210 * $f['results_factor']), 'conv' => (int) round(11 * $f['results_factor']), 'qs' => 9, 'status' => 'ENABLED'],
            ['keyword' => 'diş implantı fiyat', 'match' => 'Broad', 'ad_group' => 'Implant — Price Intent', 'spend' => round(7800 * $f['spend_factor']), 'clicks' => (int) round(520 * $f['results_factor']), 'conv' => (int) round(9 * $f['results_factor']), 'qs' => 5, 'status' => 'ENABLED'],
            ['keyword' => 'atlas dental', 'match' => 'Exact', 'ad_group' => 'Brand Exact', 'spend' => round(2100 * $f['spend_factor']), 'clicks' => (int) round(310 * $f['results_factor']), 'conv' => (int) round(18 * $f['results_factor']), 'qs' => 10, 'status' => 'ENABLED'],
            ['keyword' => 'zirkonyum kaplama ankara', 'match' => 'Phrase', 'ad_group' => 'Implant — Core Intent', 'spend' => round(3600 * $f['spend_factor']), 'clicks' => (int) round(180 * $f['results_factor']), 'conv' => (int) round(6 * $f['results_factor']), 'qs' => 7, 'status' => 'ENABLED'],
            ['keyword' => 'demo smile clinic', 'match' => 'Phrase', 'ad_group' => 'Competitor Names', 'spend' => round(2900 * $f['spend_factor']), 'clicks' => (int) round(140 * $f['results_factor']), 'conv' => (int) round(2 * $f['results_factor']), 'qs' => 4, 'status' => 'PAUSED'],
            ['keyword' => 'post bariatric dental ankara', 'match' => 'Phrase', 'ad_group' => 'Implant — Core Intent', 'spend' => round(4400 * $f['spend_factor']), 'clicks' => (int) round(190 * $f['results_factor']), 'conv' => (int) round(7 * $f['results_factor']), 'qs' => 6, 'status' => 'ENABLED'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function googleAdsAssets(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            [
                'id' => 'ga-rsa-implant',
                'type' => 'RSA',
                'name' => 'Implant RSA — Primary',
                'campaign' => 'Implant Search',
                'ad_group' => 'Implant — Core Intent',
                'status' => 'ENABLED',
                'impressions' => (int) round(42000 * $f['results_factor']),
                'clicks' => (int) round(3100 * $f['results_factor']),
                'conv' => (int) round(48 * $f['results_factor']),
                'ctr' => round(7.4 / max(0.85, $f['efficiency_factor']), 2),
                'headlines' => ['Dental Implants in Ankara', 'Atlas Dental Specialists', 'Transparent Implant Plans'],
            ],
            [
                'id' => 'ga-rsa-brand',
                'type' => 'RSA',
                'name' => 'Brand RSA',
                'campaign' => 'Brand',
                'ad_group' => 'Brand Exact',
                'status' => 'ENABLED',
                'impressions' => (int) round(12000 * $f['results_factor']),
                'clicks' => (int) round(2100 * $f['results_factor']),
                'conv' => (int) round(36 * $f['results_factor']),
                'ctr' => round(17.5 / max(0.85, $f['efficiency_factor']), 2),
                'headlines' => ['Atlas Dental Ankara', 'Book Your Visit', 'Trusted Local Clinic'],
            ],
            [
                'id' => 'ga-sitelink-implant',
                'type' => 'Sitelink',
                'name' => 'Sitelink · Implant package',
                'campaign' => 'Implant Search',
                'ad_group' => null,
                'status' => 'ENABLED',
                'impressions' => (int) round(18000 * $f['results_factor']),
                'clicks' => (int) round(420 * $f['results_factor']),
                'conv' => (int) round(8 * $f['results_factor']),
                'ctr' => 2.3,
                'headlines' => ['Implant packages'],
            ],
            [
                'id' => 'ga-callout',
                'type' => 'Callout',
                'name' => 'Callout · Same-week consult',
                'campaign' => 'Local Services',
                'ad_group' => null,
                'status' => 'ENABLED',
                'impressions' => (int) round(9800 * $f['results_factor']),
                'clicks' => null,
                'conv' => null,
                'ctr' => null,
                'headlines' => ['Same-week consultation'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function googleConversions(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            [
                'id' => 'conv-lead-form',
                'name' => 'Website lead form',
                'category' => 'Submit lead form',
                'source' => 'Website',
                'count' => (int) round(86 * $f['results_factor']),
                'value' => round(86000 * $f['results_factor']),
                'cpa' => round(620 * $f['efficiency_factor']),
                'status' => 'active',
            ],
            [
                'id' => 'conv-call',
                'name' => 'Phone call (GBP + Ads)',
                'category' => 'Phone call lead',
                'source' => 'Calls from ads',
                'count' => (int) round(24 * $f['results_factor']),
                'value' => round(36000 * $f['results_factor']),
                'cpa' => round(540 * $f['efficiency_factor']),
                'status' => 'active',
            ],
            [
                'id' => 'conv-whatsapp',
                'name' => 'WhatsApp click',
                'category' => 'Contact',
                'source' => 'Website',
                'count' => (int) round(16 * $f['results_factor']),
                'value' => null,
                'cpa' => round(410 * $f['efficiency_factor']),
                'status' => 'active',
            ],
            [
                'id' => 'conv-offline',
                'name' => 'Offline consult booked',
                'category' => 'Qualified lead',
                'source' => 'Import (demo)',
                'count' => (int) round(12 * $f['results_factor']),
                'value' => round(48000 * $f['results_factor']),
                'cpa' => round(980 * $f['efficiency_factor']),
                'status' => 'needs_review',
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
            'seasonality' => self::seasonalityNote($preset),
            'kpis' => [
                ['label' => 'Sessions', 'value' => (int) round(23840 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'sessions'), 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Organic Clicks', 'value' => (int) round(8420 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'clicks'), 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Website Leads', 'value' => (int) round(202 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'leads'), 'tone' => 'bad', 'family' => 'result'],
                ['label' => 'Conversion Rate', 'value' => round(0.85 * $f['results_factor'] / max(0.5, $f['spend_factor']), 2), 'format' => 'pct', 'delta' => self::compareDelta($preset, 'conv_rate'), 'tone' => 'warn', 'family' => 'efficiency'],
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
            'content' => self::websiteContent($preset),
            'performance' => self::websitePerformance($preset),
            'top_pages' => [
                ['path' => '/implant', 'sessions' => (int) round(6200 * $f['results_factor']), 'leads' => (int) round(88 * $f['results_factor'])],
                ['path' => '/post-bariatric', 'sessions' => (int) round(4100 * $f['results_factor']), 'leads' => (int) round(54 * $f['results_factor'])],
                ['path' => '/', 'sessions' => (int) round(3800 * $f['results_factor']), 'leads' => (int) round(22 * $f['results_factor'])],
                ['path' => '/contact', 'sessions' => (int) round(2100 * $f['results_factor']), 'leads' => (int) round(31 * $f['results_factor'])],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function websiteContent(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'pages' => [
                [
                    'path' => '/implant',
                    'title' => 'Dental Implants in Ankara',
                    'status' => 'published',
                    'word_count' => 1480,
                    'last_updated' => '12 Jun 2026',
                    'indexed' => true,
                    'issues' => ['Thin FAQ block', 'Hero image oversized'],
                    'organic_clicks' => (int) round(2100 * $f['results_factor']),
                ],
                [
                    'path' => '/post-bariatric',
                    'title' => 'Post-Bariatric Contouring',
                    'status' => 'published',
                    'word_count' => 980,
                    'last_updated' => '02 May 2026',
                    'indexed' => true,
                    'issues' => ['Missing H2 for recovery timeline'],
                    'organic_clicks' => (int) round(860 * $f['results_factor']),
                ],
                [
                    'path' => '/blog/implant-bakimi',
                    'title' => 'Implant care guide',
                    'status' => 'draft',
                    'word_count' => 640,
                    'last_updated' => '28 Jul 2026',
                    'indexed' => false,
                    'issues' => ['Not published'],
                    'organic_clicks' => 0,
                ],
                [
                    'path' => '/team',
                    'title' => 'Our specialists',
                    'status' => 'published',
                    'word_count' => 720,
                    'last_updated' => '18 Mar 2026',
                    'indexed' => true,
                    'issues' => [],
                    'organic_clicks' => (int) round(410 * $f['results_factor']),
                ],
            ],
            'opportunities' => [
                'Expand /implant FAQ for “implant ankara fiyat” intent.',
                'Publish implant care guide to capture informational queries currently wasted in paid search.',
                'Add LocalBusiness schema on homepage and /contact.',
            ],
        ];
    }

    /**
     * Field vs lab vitals for website performance UI.
     *
     * @return array<string, mixed>
     */
    public static function websitePerformance(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);
        // Poorer efficiency windows also imply slightly worse perceived performance.
        $labPenalty = max(0.0, ($f['efficiency_factor'] - 1) * 0.6);

        return [
            'period_label' => $f['label'],
            'field' => [
                ['metric' => 'LCP', 'mobile' => round(3.6 + $labPenalty, 1).'s', 'desktop' => '2.1s', 'rating' => 'needs_improvement'],
                ['metric' => 'INP', 'mobile' => '180ms', 'desktop' => '90ms', 'rating' => 'good'],
                ['metric' => 'CLS', 'mobile' => '0.12', 'desktop' => '0.04', 'rating' => 'needs_improvement'],
                ['metric' => 'TTFB', 'mobile' => '0.9s', 'desktop' => '0.6s', 'rating' => 'good'],
            ],
            'lab' => [
                ['metric' => 'LCP', 'mobile' => round(4.1 + $labPenalty, 1).'s', 'desktop' => '2.4s', 'rating' => 'poor', 'page' => '/implant'],
                ['metric' => 'INP', 'mobile' => '210ms', 'desktop' => '110ms', 'rating' => 'needs_improvement', 'page' => '/implant'],
                ['metric' => 'CLS', 'mobile' => '0.18', 'desktop' => '0.05', 'rating' => 'poor', 'page' => '/implant'],
                ['metric' => 'Speed Index', 'mobile' => round(5.2 + $labPenalty, 1).'s', 'desktop' => '2.8s', 'rating' => 'needs_improvement', 'page' => '/implant'],
            ],
            'notes' => [
                'Field data (CrUX-style) is healthier than lab on homepage; /implant lab LCP remains the paid-landing bottleneck.',
                'Hero media and third-party chat widget dominate lab LCP on mobile.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function domainOverview(): array
    {
        return [
            'domain' => 'atlasdental.example',
            'registrar' => 'Demo Registrar',
            'registered_at' => '21 Mar 2019',
            'expires_at' => '21 Mar 2027',
            'days_remaining' => 221,
            'auto_renew' => true,
            'status' => 'active',
            'provenance' => 'Detected',
            'dns' => [
                'health' => 'healthy',
                'nameservers' => ['ns1.demohost.example', 'ns2.demohost.example'],
                'records' => [
                    ['type' => 'A', 'host' => '@', 'value' => '203.0.113.42'],
                    ['type' => 'A', 'host' => 'www', 'value' => '203.0.113.42'],
                    ['type' => 'MX', 'host' => '@', 'value' => 'mail.demohost.example'],
                    ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:_spf.demohost.example ~all'],
                ],
                'issues' => [],
            ],
            'ssl' => [
                'issuer' => 'Demo CA',
                'expires_at' => '09 Nov 2026',
                'days_remaining' => 89,
                'grade' => 'A',
                'san' => ['atlasdental.example', 'www.atlasdental.example'],
                'provenance' => 'Detected',
            ],
            'whois_summary' => 'Registrant organization masked · Türkiye contact',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function hostingOverview(): array
    {
        return [
            'provider' => 'DemoHost',
            'plan' => 'Business Cloud',
            'environment' => 'production',
            'region' => 'eu-central',
            'renewal_at' => '15 Sep 2026',
            'days_remaining' => 34,
            'status' => 'renewal_due',
            'provenance' => 'Manual',
            'uptime' => [
                '30d' => 99.97,
                '90d' => 99.95,
                'incidents' => [
                    ['when' => '12 Jun 2026', 'duration' => '8 min', 'note' => 'Scheduled network maintenance'],
                ],
            ],
            'backups' => [
                'last_full' => 'Today 03:10',
                'last_incremental' => 'Today 09:10',
                'retention_days' => 14,
                'status' => 'healthy',
            ],
            'resources' => [
                'cpu' => '38%',
                'memory' => '61%',
                'disk' => '72%',
                'php' => '8.3',
            ],
            'notes' => [
                'Renewal reminder in 34 days — no immediate outage risk.',
                'Disk trending up after media uploads for implant landing page.',
            ],
        ];
    }

    /**
     * Operator dashboard composition for Demo Mode.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(): array
    {
        $brand = self::brand();

        return [
            'needs_attention' => self::brandAttention(),
            'brand_cards' => [
                [
                    'id' => $brand['id'],
                    'name' => $brand['name'],
                    'health' => $brand['health'],
                    'health_label' => $brand['health_label'],
                    'media_spend' => $brand['summary']['media_spend'],
                    'platform_leads' => $brand['summary']['platform_leads'],
                    'website_leads' => $brand['summary']['website_leads'],
                    'open_tasks' => $brand['open_tasks'],
                    'open_findings' => count(self::findings()),
                    'route' => 'demo.brand',
                    'route_params' => ['brand' => self::BRAND_ID],
                ],
            ],
            'movements' => [
                [
                    'label' => 'Meta CPL',
                    'direction' => 'up',
                    'value' => '+22%',
                    'detail' => 'Post Bariatric — Europe driving deterioration',
                    'tone' => 'bad',
                    'route' => 'demo.meta.overview',
                ],
                [
                    'label' => 'Google Ads CPA',
                    'direction' => 'flat',
                    'value' => '+1.8%',
                    'detail' => 'Stable overall; waste share elevated',
                    'tone' => 'neutral',
                    'route' => 'demo.google-ads.overview',
                ],
                [
                    'label' => 'GBP map rank',
                    'direction' => 'down',
                    'value' => '4.8 → 5.4',
                    'detail' => '“implant ankara” NE grid weakened',
                    'tone' => 'warn',
                    'route' => 'demo.gbp',
                ],
                [
                    'label' => 'Website leads',
                    'direction' => 'down',
                    'value' => '-4%',
                    'detail' => 'Mobile LCP drag on /implant',
                    'tone' => 'bad',
                    'route' => 'demo.website',
                ],
            ],
            'upcoming' => [
                [
                    'label' => 'Hosting renewal',
                    'when' => '15 Sep 2026',
                    'detail' => 'DemoHost · 34 days',
                    'route' => 'demo.website',
                ],
                [
                    'label' => 'SSL certificate',
                    'when' => '09 Nov 2026',
                    'detail' => '89 days remaining',
                    'route' => 'demo.website',
                ],
                [
                    'label' => 'Creative replacement task due',
                    'when' => 'Friday',
                    'detail' => 'Replace PB-Video-03',
                    'route' => 'demo.tasks',
                ],
            ],
            'recent_operations' => self::activitySeed(),
            'seasonality' => self::seasonalityNote('last_28'),
        ];
    }

    /**
     * Public discovery result cards with explicit provenance.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicDiscoverySteps(): array
    {
        return [
            [
                'step' => 'Website analyzed',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'atlasdental.example',
                    'summary' => 'Public site detected · dental clinic · Ankara focus · lead forms present',
                    'signals' => ['HTTPS', 'Contact form', 'WhatsApp CTA', 'Implant landing page'],
                ],
            ],
            [
                'step' => 'Search presence discovered',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'Organic + Maps presence',
                    'summary' => 'Brand and service queries surface in public SERP samples',
                    'signals' => ['Brand query position ~1.4', 'implant ankara in top 10'],
                ],
            ],
            [
                'step' => 'Google Maps listing found',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'Atlas Dental Ankara',
                    'summary' => 'Public Maps listing · 4.8★ · Çankaya area',
                    'signals' => ['Open now pattern', 'Phone visible', 'Website linked'],
                ],
            ],
            [
                'step' => 'Review sources found',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'Review footprint',
                    'summary' => 'Google reviews dominant; secondary directories mentioned publicly',
                    'signals' => ['1264 Google reviews', 'Themes: staff, wait time'],
                ],
            ],
            [
                'step' => 'Competitors identified',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'Local competitor set',
                    'summary' => 'Candidate competitors for human review — not auto-accepted',
                    'signals' => ['Demo Smile Clinic', 'Ankara Implant Center (demo)'],
                ],
            ],
            [
                'step' => 'Domain / SSL information found',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'Domain & TLS',
                    'summary' => 'Active domain · valid SSL · expiry tracked',
                    'signals' => ['Expiry 21 Mar 2027', 'SSL until 09 Nov 2026'],
                ],
            ],
            [
                'step' => 'Public social references found',
                'status' => 'completed',
                'provenance' => 'PUBLIC DISCOVERY',
                'card' => [
                    'title' => 'Social references',
                    'summary' => 'Public Instagram / Facebook pages referenced from site footer',
                    'signals' => ['Instagram handle present', 'No write access — discovery only'],
                ],
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
                'asset_type' => 'meta_ads',
                'asset_id' => self::META_ASSET_ID,
                'title' => 'Meta CPL deteriorated',
                'evidence' => 'CPL ₺482 → ₺691 on Post Bariatric — Europe (14 days).',
                'detected' => '2 Jul 2026',
                'status' => 'open',
                'type' => 'performance',
            ],
            [
                'id' => 'f-meta-freq',
                'severity' => 'medium',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'asset_type' => 'meta_ads',
                'asset_id' => self::META_ASSET_ID,
                'title' => 'Creative frequency elevated',
                'evidence' => 'PB-Video-03 frequency 2.8 with CTR 1.4% — fatigue signal.',
                'detected' => '2 Jul 2026',
                'status' => 'open',
                'type' => 'performance',
            ],
            [
                'id' => 'f-web-lcp',
                'severity' => 'medium',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Website',
                'asset_type' => 'website',
                'asset_id' => self::WEBSITE_ASSET_ID,
                'title' => 'Website mobile performance degraded',
                'evidence' => 'Mobile LCP 4.1s on /implant primary landing page.',
                'detected' => '3 Jul 2026',
                'status' => 'open',
                'type' => 'technical',
            ],
            [
                'id' => 'f-web-canonical',
                'severity' => 'low',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Website',
                'asset_type' => 'website',
                'asset_id' => self::WEBSITE_ASSET_ID,
                'title' => 'Missing canonicals',
                'evidence' => '7 pages without canonical tags.',
                'detected' => '3 Jul 2026',
                'status' => 'open',
                'type' => 'technical',
            ],
            [
                'id' => 'f-gads-waste',
                'severity' => 'high',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Ads',
                'asset_type' => 'google_ads',
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
                'asset_type' => 'gbp',
                'asset_id' => self::GBP_ASSET_ID,
                'title' => 'GBP local visibility declined',
                'evidence' => '"implant ankara" avg map rank 4.8 → 5.4; NE grid weakened.',
                'detected' => '4 Jul 2026',
                'status' => 'open',
                'type' => 'local',
            ],
            [
                'id' => 'f-gbp-unanswered',
                'severity' => 'low',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Business Profile',
                'asset_type' => 'gbp',
                'asset_id' => self::GBP_ASSET_ID,
                'title' => 'Unanswered reviews accumulating',
                'evidence' => '12 unanswered reviews; waiting-time theme recurring.',
                'detected' => '5 Jul 2026',
                'status' => 'open',
                'type' => 'local',
            ],
            [
                'id' => 'f-host-renew',
                'severity' => 'info',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Hosting',
                'asset_type' => 'hosting',
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
    public static function filterFindings(?string $severity = null, ?string $assetType = null): array
    {
        $rows = self::findings();

        if ($severity !== null && $severity !== '' && strtolower($severity) !== 'all') {
            $needle = strtolower($severity);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => strtolower((string) $row['severity']) === $needle
            ));
        }

        if ($assetType !== null && $assetType !== '' && strtolower($assetType) !== 'all') {
            $needle = strtolower($assetType);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => strtolower((string) ($row['asset_type'] ?? '')) === $needle
            ));
        }

        return $rows;
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
            [
                'id' => 'r-gbp-reviews',
                'finding_id' => 'f-gbp-unanswered',
                'title' => 'Clear unanswered GBP review backlog',
                'observation' => '12 unanswered reviews with recurring waiting-time theme.',
                'why' => 'Public response velocity supports local trust and map engagement.',
                'evidence' => 'GBP reviews · Connected provider (demo)',
                'action' => 'Respond to open reviews; acknowledge waiting-time theme with operational note.',
                'dependencies' => 'Clinic ops confirmation for wait-time messaging.',
                'success' => 'Unanswered reviews ≤ 3 within 7 days.',
                'failure' => 'Backlog remains ≥ 10 after 7 days.',
                'watch' => ['Unanswered count', 'New review rating'],
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Business Profile',
            ],
            [
                'id' => 'r-map-relevance',
                'finding_id' => 'f-gbp-map',
                'title' => 'Review local relevance for “implant ankara”',
                'observation' => 'Avg map rank worsened 4.8 → 5.4; NE grid weakened.',
                'why' => 'Primary local demand keyword for implant acquisition.',
                'evidence' => 'External search intelligence map grid (demo)',
                'action' => 'Audit GBP categories, services, and photo freshness; compare competitor density.',
                'dependencies' => 'GBP edit access',
                'success' => 'Avg map rank trending ≤ 5.0 within 28 days.',
                'failure' => 'Rank remains ≥ 5.4 with NE cells still weak.',
                'watch' => ['Avg map rank', 'Top3 share'],
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Business Profile',
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
            [
                'date' => 'May 18',
                'event' => 'Seasonality shift detected',
                'detail' => 'Meta lead efficiency began deteriorating vs April baseline',
                'actor' => 'System',
                'provenance' => 'Connected provider',
            ],
            [
                'date' => 'Jul 2',
                'event' => 'Finding detected',
                'detail' => 'Meta CPL deterioration on Post Bariatric — Europe',
                'actor' => 'System',
                'provenance' => 'Connected provider',
            ],
            [
                'date' => 'Jul 2',
                'event' => 'Finding detected',
                'detail' => 'Creative PB-Video-03 frequency/CTR fatigue signal',
                'actor' => 'System',
                'provenance' => 'Connected provider',
            ],
            [
                'date' => 'Jul 3',
                'event' => 'Recommendation drafted',
                'detail' => 'Replace underperforming Meta creative PB-Video-03',
                'actor' => 'Demo AI brief',
                'provenance' => 'AI guidance (demo — no live call)',
            ],
            [
                'date' => 'Jul 3',
                'event' => 'Recommendation approved',
                'detail' => 'Creative replacement approved by operator',
                'actor' => 'Operator',
                'provenance' => 'Operator action',
            ],
            [
                'date' => 'Jul 4',
                'event' => 'Task created',
                'detail' => 'Assigned to Ayşe Yılmaz · due Friday',
                'actor' => 'Operator',
                'provenance' => 'Operator action',
            ],
            [
                'date' => 'Jul 4',
                'event' => 'Finding detected',
                'detail' => 'GBP “implant ankara” map rank 4.8 → 5.4',
                'actor' => 'System',
                'provenance' => 'External search intelligence',
            ],
            [
                'date' => 'Jul 7',
                'event' => 'External work noted',
                'detail' => 'New creative launched externally (manual note)',
                'actor' => 'Ayşe Yılmaz',
                'provenance' => 'Manual note',
            ],
            [
                'date' => 'Jul 21',
                'event' => 'Follow-up evaluated',
                'detail' => 'New data collected for 14-day window',
                'actor' => 'System',
                'provenance' => 'Connected provider',
            ],
            [
                'date' => 'Jul 21',
                'event' => 'Associated improvement observed',
                'detail' => 'CPL ₺691 → ₺548 over follow-up period (not claiming causality)',
                'actor' => 'System',
                'provenance' => 'Connected provider',
            ],
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
        return self::brandAiAnalysis();
    }

    /**
     * Richer brand AI analysis payload (demo — no live model call).
     *
     * @return array<string, mixed>
     */
    public static function brandAiAnalysis(): array
    {
        return [
            'headline' => 'Brand analysis · Atlas Dental Ankara',
            'period_context' => self::seasonalityNote('last_28'),
            'points' => [
                'Meta Ads is the largest immediate efficiency issue — Post Bariatric — Europe CPL deteriorated while spend stayed material.',
                'Website mobile performance on /implant may amplify paid-media inefficiency across Meta and Google.',
                'Google Ads remains comparatively stable, but search-term waste and competitor/irrelevant queries dilute budget.',
                'GBP review velocity is healthy; north-east local visibility for “implant ankara” weakened and unanswered reviews are stacking.',
                'Infrastructure is not blocking performance today; hosting renewal is a near-term lifecycle reminder only.',
            ],
            'evidence_links' => [
                ['label' => 'Meta Ads', 'route' => 'demo.meta.overview'],
                ['label' => 'Website', 'route' => 'demo.website'],
                ['label' => 'Google Ads', 'route' => 'demo.google-ads.overview'],
                ['label' => 'GBP', 'route' => 'demo.gbp'],
            ],
            'priorities' => [
                'Replace underperforming Meta creative PB-Video-03.',
                'Fix landing-page mobile LCP on /implant.',
                'Add negatives for Irrelevant / Negative-candidate search terms.',
                'Review local relevance for “implant ankara” and clear review backlog.',
            ],
            'risks' => [
                'Continuing spend on fatigued Meta creative without replacement.',
                'Paid traffic landing on a slow mobile page.',
                'Waste share remaining above 10% of Google Ads spend.',
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
