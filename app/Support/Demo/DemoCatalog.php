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
     * @return array<string, mixed>
     */
    public static function customer(): array
    {
        return [
            'id' => self::CUSTOMER_ID,
            'name' => 'Atlas Health Group',
            'legal_name' => 'Atlas Health Group Sağlık Hizmetleri A.Ş.',
            'type' => 'company',
            'status' => 'active',
            'industry' => 'healthcare',
            'industry_other' => null,
            'hq_country' => 'TR',
            'hq_city' => 'Ankara',
            'hq' => 'Ankara, Türkiye',
            'primary_email' => 'ops@atlashealth.example',
            'primary_phone' => '+90 312 000 00 00',
            'service_started_at' => '2024-03-01',
            'services' => ['meta_ads', 'google_ads', 'seo', 'local_seo', 'website_maintenance'],
            'responsible_user_ids' => ['u-ayse', 'u-mert'],
            'brands_count' => 1,
            'digital_assets_count' => 8,
            'open_findings' => 4,
            'open_tasks' => 3,
            'overdue_tasks' => 1,
            // Backward-compatible aliases used by older demo surfaces
            'open_issues' => 4,
        ];
    }

    /**
     * Demo internal team members (session-only; not operator DB users).
     *
     * @return list<array{id: string, name: string, initials: string, email: string}>
     */
    public static function teamMembers(): array
    {
        return [
            ['id' => 'u-ayse', 'name' => 'Ayşe Demir', 'initials' => 'AD', 'email' => 'ayse@moximu.example'],
            ['id' => 'u-mert', 'name' => 'Mert Yılmaz', 'initials' => 'MY', 'email' => 'mert@moximu.example'],
            ['id' => 'u-selin', 'name' => 'Selin Kaya', 'initials' => 'SK', 'email' => 'selin@moximu.example'],
            ['id' => 'u-can', 'name' => 'Can Öztürk', 'initials' => 'CÖ', 'email' => 'can@moximu.example'],
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
            'sector' => 'dental',
            'industry' => 'dental',
            'primary_country' => 'TR',
            'target_markets' => ['TR', 'DE', 'GB'],
            'languages' => ['tr', 'en', 'de'],
            'location' => 'Ankara, Türkiye',
            'description' => 'Dental clinic brand focused on implant and post-bariatric dental care.',
            'audience' => 'Adults seeking implant and aesthetic dental treatments in Ankara and EU medical travel.',
            'offerings' => "Dental implants\nPost-bariatric dentistry\nSmile design",
            'competitors' => "Ankara Implant Center\nSmile Ankara Clinic",
            'responsible_user_ids' => ['u-ayse', 'u-selin'],
            'website' => 'https://atlasdental.example',
            'health' => 'needs_attention',
            'health_label' => 'Needs attention',
            'assets_count' => 8,
            'connected_assets' => 7,
            'open_findings' => 4,
            'open_recommendations' => 3,
            'open_tasks' => 3,
            'overdue_tasks' => 1,
            'context_completed' => 6,
            'context_total' => 8,
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
            // GA4 / GSC are first-class managed Digital Assets that can also provide Evidence to siblings.
            'website', 'meta_ads', 'google_ads', 'gbp', 'ga4', 'gsc' => [
                'role' => 'primary_managed',
                'role_label' => 'Primary managed asset',
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
                'open_findings' => 3,
                'open_tasks' => 1,
                'last_update' => 'Today',
                'route' => 'operator.website',
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
                'route' => 'operator.gbp',
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
                'route' => 'operator.meta.overview',
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
                'route' => 'operator.google-ads.overview',
            ],
            [
                'id' => self::GA4_ASSET_ID,
                'type' => 'ga4',
                'type_label' => 'Google Analytics',
                'name' => 'Atlas Dental — GA4',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'needs_attention',
                'health_label' => 'Needs attention',
                'open_findings' => 3,
                'open_tasks' => 2,
                'last_update' => 'Today',
                'route' => 'operator.analytics',
                'relationship_summary' => 'Measures Website · Evidence for Ads',
            ],
            [
                'id' => self::GSC_ASSET_ID,
                'type' => 'gsc',
                'type_label' => 'Google Search Console',
                'name' => 'Atlas Dental — Search Console',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'needs_attention',
                'health_label' => 'Needs attention',
                'open_findings' => 4,
                'open_tasks' => 2,
                'last_update' => 'Today',
                'route' => 'operator.search-console',
                'relationship_summary' => 'Observes Website · Evidence for SEO / Ads / GBP',
            ],
            [
                'id' => self::DOMAIN_ASSET_ID,
                'type' => 'domain',
                'type_label' => 'Domain (legacy)',
                'name' => 'atlasdental.example',
                'brand_id' => self::BRAND_ID,
                'connection' => 'detected',
                'provenance' => 'Detected',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'detail' => 'Legacy — use Website → Infrastructure',
                'open_findings' => 0,
                'open_tasks' => 0,
                'last_update' => 'Detected',
                'route' => 'operator.website',
                'route_params' => ['tab' => 'infrastructure'],
                'legacy_deprecated' => true,
            ],
            [
                'id' => self::HOSTING_ASSET_ID,
                'type' => 'hosting',
                'type_label' => 'Hosting (legacy)',
                'name' => 'DemoHost · Atlas Dental',
                'brand_id' => self::BRAND_ID,
                'connection' => 'manual',
                'provenance' => 'Manual',
                'health' => 'warning',
                'health_label' => 'Renewal due',
                'detail' => 'Legacy — use Website → Infrastructure',
                'open_findings' => 1,
                'open_tasks' => 0,
                'last_update' => 'Manual',
                'route' => 'operator.website',
                'route_params' => ['tab' => 'infrastructure'],
                'legacy_deprecated' => true,
            ],
        ];

        return array_map(static function (array $asset): array {
            $merged = array_merge(
                $asset,
                self::assetTaxonomy((string) $asset['type']),
                self::assetPrimaryMetric($asset),
            );

            return GlobalOperatingFixtures::enrichAsset($merged);
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array{primary_metric: string, primary_metric_label: string}
     */
    public static function assetPrimaryMetric(array $asset): array
    {
        return match ((string) ($asset['type'] ?? '')) {
            'website' => [
                'primary_metric' => (string) ($asset['open_findings'] ?? 0),
                'primary_metric_label' => (string) ($asset['health_label'] ?? 'Needs attention'),
            ],
            'gbp' => [
                'primary_metric' => isset($asset['rating']) ? number_format((float) $asset['rating'], 1).'★' : '—',
                'primary_metric_label' => 'Rating',
            ],
            'meta_ads' => [
                'primary_metric' => (string) ($asset['detail'] ?? 'CPL watch'),
                'primary_metric_label' => 'Efficiency signal',
            ],
            'google_ads' => [
                'primary_metric' => (string) ($asset['detail'] ?? 'CPA stable'),
                'primary_metric_label' => 'Efficiency signal',
            ],
            'ga4' => [
                'primary_metric' => (string) ($asset['open_findings'] ?? 0).' findings',
                'primary_metric_label' => 'Open findings',
            ],
            'gsc' => [
                'primary_metric' => (string) ($asset['relationship_summary'] ?? 'Observes Website'),
                'primary_metric_label' => 'Relationship',
            ],
            'domain' => [
                'primary_metric' => (string) ($asset['detail'] ?? 'Active'),
                'primary_metric_label' => 'Lifecycle',
            ],
            'hosting' => [
                'primary_metric' => (string) ($asset['detail'] ?? '—'),
                'primary_metric_label' => 'Renewal window',
            ],
            default => [
                'primary_metric' => (string) ($asset['health_label'] ?? '—'),
                'primary_metric_label' => 'Status',
            ],
        };
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
     * Custom ranges resolve from DemoState dates (or optional overrides) via DemoPeriod.
     *
     * @return array{spend_factor: float, results_factor: float, efficiency_factor: float, label: string, narrative: string, days?: int, start?: string, end?: string}
     */
    public static function periodFactors(string $preset, ?string $start = null, ?string $end = null): array
    {
        return DemoPeriod::factors($preset, $start, $end);
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
     * @return list<array<string, mixed>>
     */
    public static function metaCampaigns(string $preset = 'last_28'): array
    {
        $workspace = MetaAdsWorkspaceFixtures::workspace($preset);
        $rows = [];
        foreach ($workspace['campaigns'] as $campaign) {
            $rows[] = [
                'id' => $campaign['id'],
                'name' => $campaign['name'],
                'status' => $campaign['status'],
                'objective' => match ($campaign['objective_family']) {
                    'Leads' => 'OUTCOME_LEADS',
                    'Messaging' => 'OUTCOME_ENGAGEMENT',
                    'Awareness' => 'OUTCOME_AWARENESS',
                    default => 'OUTCOME_LEADS',
                },
                'spend' => $campaign['spend'],
                'results' => $campaign['results'],
                'result_label' => $campaign['result_label'],
                'cost_result' => $campaign['cost_result'],
                'reach' => $campaign['reach'],
                'frequency' => $campaign['frequency'],
                'ctr' => $campaign['ctr'],
                'attention' => $campaign['attention_primary'] ? strtolower((string) $campaign['attention_primary']) : null,
                'trend' => [4, 5, 6, 7, 8, 9, 8],
            ];
        }

        return $rows;
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
                    'inventory_state' => 'Needs refresh',
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
                    'inventory_state' => 'Strong',
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
                    'inventory_state' => 'Opportunity',
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
                    'inventory_state' => 'Thin',
                    'word_count' => 720,
                    'last_updated' => '18 Mar 2026',
                    'indexed' => true,
                    'issues' => ['Short bios', 'No credentials schema'],
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
     * @return list<array<string, mixed>>
     */
    public static function findings(): array
    {
        return [
            [
                'id' => 'f-lead-measurement',
                'severity' => 'critical',
                'brand' => 'Atlas Dental Ankara',
                'customer' => 'Atlas Health Group',
                'asset' => 'Google Ads',
                'asset_type' => 'google_ads',
                'asset_id' => self::GOOGLE_ADS_ASSET_ID,
                'title' => 'Lead measurement requires investigation',
                'plain' => 'Primary lead conversion signal has been missing for 36 hours across affected campaigns.',
                'observation' => '36h without primary lead signal · 3 campaigns affected.',
                'why' => 'Paid acquisition optimization cannot be trusted without the primary conversion signal.',
                'evidence' => 'Conversion tracking gap on lead form / generate_lead mapping (demo).',
                'detected' => '12 Aug 2026',
                'last_observed' => '36h ago',
                'status' => 'open',
                'type' => 'measurement',
                'category' => 'Measurement',
                'source' => 'deterministic_analysis',
                'source_label' => 'Deterministic analysis',
                'recommendation_id' => 'r-review-conversion-mapping',
                'task_id' => 't-investigate-lead-measurement',
                'recommendations_count' => 1,
                'tasks_count' => 1,
            ],
            [
                'id' => 'f-meta-cpl',
                'severity' => 'critical',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'asset_type' => 'meta_ads',
                'asset_id' => self::META_ASSET_ID,
                'title' => 'Meta CPL deteriorated',
                'plain' => 'Lead cost on Post Bariatric — Europe rose sharply versus the prior window.',
                'observation' => 'CPL moved from ₺482 to ₺691 over 14 days on the primary Europe campaign.',
                'why' => 'Largest paid-efficiency drag on the brand; spend remains material while conversion cost worsens.',
                'evidence' => 'CPL ₺482 → ₺691 on Post Bariatric — Europe (14 days).',
                'detected' => '2 Jul 2026',
                'last_observed' => '2 days ago',
                'status' => 'open',
                'type' => 'performance',
                'category' => 'Performance',
                'source' => 'provider',
                'source_label' => 'Provider',
                'recommendations_count' => 1,
                'tasks_count' => 1,
            ],
            [
                'id' => 'f-meta-freq',
                'severity' => 'medium',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'asset_type' => 'meta_ads',
                'asset_id' => self::META_ASSET_ID,
                'title' => 'Creative frequency elevated',
                'plain' => 'Top creative is over-exposed with weakening CTR.',
                'observation' => 'PB-Video-03 frequency is 2.8 while Link CTR sits at 1.4%.',
                'why' => 'Fatigue on a high-spend creative typically precedes further CPL deterioration.',
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
                'plain' => 'Primary landing page is slow on mobile.',
                'observation' => 'Mobile LCP is 4.1s on /implant.',
                'why' => 'Weak mobile experience can amplify paid-media inefficiency across Meta and Google.',
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
                'plain' => 'Several pages lack canonical tags.',
                'observation' => '7 pages currently report no canonical.',
                'why' => 'Index clarity suffers; duplicate-risk pages compete with primary URLs.',
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
                'plain' => 'Material spend is landing on low-relevance search terms.',
                'observation' => '₺12,240 (12.7% of spend) hit low-relevance queries.',
                'why' => 'Immediate budget efficiency lever without expanding bids.',
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
                'plain' => 'Local map visibility for the primary implant keyword weakened.',
                'observation' => '"implant ankara" avg map rank moved 4.8 → 5.4 with NE grid softness.',
                'why' => 'Primary local demand keyword for implant acquisition.',
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
                'plain' => 'Review response backlog is growing.',
                'observation' => '12 unanswered reviews; waiting-time theme recurs.',
                'why' => 'Public response velocity supports local trust and map engagement.',
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
                'plain' => 'Hosting renewal is approaching — no outage signal.',
                'observation' => 'DemoHost renewal is due 15 Sep 2026 (34 days).',
                'why' => 'Lifecycle awareness so renewals are planned, not reactive.',
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
                'id' => 'r-review-conversion-mapping',
                'finding_id' => 'f-lead-measurement',
                'title' => 'Review conversion mapping for primary lead signal',
                'observation' => 'Primary lead conversion has been dark for 36 hours across three Google Ads campaigns.',
                'why' => 'Without a trustworthy lead signal, spend and optimization decisions are blind.',
                'evidence' => 'Deterministic conversion gap · Google Ads · GA4 evidence correlation (demo)',
                'action' => 'Verify lead form → Google Ads conversion → GA4 business action mapping; restore primary lead signal.',
                'dependencies' => 'Access to tag manager / conversion settings outside MoxDOP.',
                'success' => 'Primary lead conversions resume within 48h and remain stable for 7 days.',
                'failure' => 'Lead signal remains dark after mapping review.',
                'watch' => ['Lead conversions', 'Campaign conversion rate'],
                'effort' => 'Medium',
                'verification_plan' => 'Confirm next successful lead conversion event in Ads + GA4 within 48 hours.',
                'provenance' => 'Deterministic',
                'ai_assisted' => false,
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Ads',
                'asset_type' => 'google_ads',
                'priority' => 'high',
            ],
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
                'effort' => 'Medium',
                'verification_plan' => 'Compare CPL / CTR over 14 days after creative launch.',
                'provenance' => 'Deterministic + operator review',
                'ai_assisted' => false,
                'status' => 'pending',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'asset_type' => 'meta_ads',
                'priority' => 'high',
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
                'id' => 't-investigate-lead-measurement',
                'title' => 'Investigate lead measurement',
                'brand' => 'Atlas Dental Ankara',
                'customer' => 'Atlas Health Group',
                'asset' => 'Google Ads',
                'asset_type' => 'google_ads',
                'digital_asset_id' => self::GOOGLE_ADS_ASSET_ID,
                'scope_level' => 'digital_asset',
                'recommendation_id' => 'r-review-conversion-mapping',
                'finding_id' => 'f-lead-measurement',
                'owner' => 'Ayşe Demir',
                'assignee_id' => 'u-ayse',
                'priority' => 'critical',
                'due' => 'Today',
                'status' => 'open',
                'origin' => 'Recommendation',
                'description' => 'Restore primary lead conversion signal mapping across Ads and GA4.',
                'success_signal' => 'Lead conversions resume within 48h.',
                'why' => [
                    'finding' => 'Lead measurement requires investigation',
                    'recommendation' => 'Review conversion mapping for primary lead signal',
                    'evidence' => '36h without primary lead signal · 3 campaigns',
                ],
                'do' => 'Audit conversion tags and business-action mapping outside MoxDOP; document restoration.',
                'measure' => 'Primary lead conversion events in Ads + GA4.',
                'follow_up' => 'Confirm outcome after next collection window — completion ≠ success.',
                'outcome' => null,
            ],
            [
                'id' => 't-replace-creative',
                'title' => 'Replace PB-Video-03 creative',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Meta Ads',
                'asset_type' => 'meta_ads',
                'digital_asset_id' => self::META_ASSET_ID,
                'scope_level' => 'digital_asset',
                'recommendation_id' => 'r-replace-creative',
                'owner' => 'Ayşe Demir',
                'assignee_id' => 'u-ayse',
                'priority' => 'high',
                'due' => 'Friday',
                'status' => 'open',
                'origin' => 'Recommendation',
                'description' => 'Produce and launch replacement creative; pause PB-Video-03 after launch.',
                'success_signal' => 'CPL trending toward ≤ ₺550 over 14 days.',
                'why' => [
                    'finding' => 'Meta CPL deteriorated on Post Bariatric — Europe',
                    'recommendation' => 'Replace underperforming Meta creative PB-Video-03',
                    'evidence' => 'CTR 2.9% → 1.4%; CPL ₺482 → ₺691',
                ],
                'do' => 'Produce and launch a replacement video creative outside MoxDOP; pause PB-Video-03 after launch.',
                'measure' => 'CPL, Link CTR, and frequency over the next 14 days.',
                'follow_up' => 'Re-evaluate the linked Meta CPL finding after the next comparable collection.',
                'outcome' => null,
            ],
            [
                'id' => 't-lcp',
                'title' => 'Improve /implant mobile LCP',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Website',
                'recommendation_id' => 'r-fix-lcp',
                'owner' => 'Can Öztürk',
                'assignee_id' => 'u-can',
                'priority' => 'medium',
                'due' => 'Next week',
                'status' => 'in_progress',
                'origin' => 'Recommendation',
                'scope_level' => 'digital_asset',
                'asset_type' => 'website',
                'digital_asset_id' => self::WEBSITE_ASSET_ID,
                'description' => 'Optimize hero media and JS on /implant.',
                'success_signal' => 'Mobile LCP ≤ 2.5s',
                'why' => [
                    'finding' => 'Website mobile performance degraded',
                    'recommendation' => 'Fix /implant mobile LCP',
                    'evidence' => 'LCP 4.1s',
                ],
                'do' => 'Compress hero media, defer non-critical JS, and verify LCP on mobile.',
                'measure' => 'Mobile LCP on re-check ≤ 2.5s.',
                'follow_up' => 'Confirm paid landing conversion rate after the performance fix ships.',
                'outcome' => null,
            ],
            [
                'id' => 't-negatives',
                'title' => 'Add Google Ads negatives for waste terms',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Ads',
                'asset_type' => 'google_ads',
                'digital_asset_id' => self::GOOGLE_ADS_ASSET_ID,
                'scope_level' => 'digital_asset',
                'recommendation_id' => 'r-negatives',
                'owner' => 'Mert Yılmaz',
                'assignee_id' => 'u-mert',
                'priority' => 'high',
                'due' => 'Tomorrow',
                'status' => 'open',
                'origin' => 'Recommendation',
                'description' => 'Negate demo low-relevance queries from search terms list.',
                'success_signal' => 'Waste share < 6%',
                'why' => [
                    'finding' => 'Google search-term waste',
                    'recommendation' => 'Add negatives for low-relevance search terms',
                    'evidence' => '₺12,240 waste',
                ],
                'do' => 'Add negatives for demo waste terms in Google Ads; review weekly.',
                'measure' => 'Waste share of spend over 14 days.',
                'follow_up' => 'Confirm the linked waste finding clears on the next Ads refresh.',
                'outcome' => null,
            ],
            [
                'id' => 't-gbp-reviews',
                'title' => 'Clear unanswered GBP review backlog',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Business Profile',
                'asset_type' => 'gbp',
                'digital_asset_id' => self::GBP_ASSET_ID,
                'scope_level' => 'digital_asset',
                'recommendation_id' => 'r-gbp-reviews',
                'owner' => 'Selin Kaya',
                'assignee_id' => 'u-selin',
                'priority' => 'medium',
                'due' => 'Waiting on clinic copy',
                'status' => 'blocked',
                'origin' => 'Recommendation',
                'description' => 'Respond to open reviews once wait-time messaging is confirmed.',
                'success_signal' => 'Unanswered reviews ≤ 3 within 7 days.',
                'why' => [
                    'finding' => 'Unanswered reviews accumulating',
                    'recommendation' => 'Clear unanswered GBP review backlog',
                    'evidence' => '12 unanswered reviews',
                ],
                'do' => 'Draft responses; confirm wait-time language with clinic ops; publish replies outside MoxDOP.',
                'measure' => 'Unanswered count and new review rating after 7 days.',
                'follow_up' => 'Re-check GBP reviews after the next collection window.',
                'outcome' => null,
            ],
            [
                'id' => 't-map-relevance',
                'title' => 'Audit GBP relevance for implant ankara',
                'brand' => 'Atlas Dental Ankara',
                'asset' => 'Google Business Profile',
                'asset_type' => 'gbp',
                'digital_asset_id' => self::GBP_ASSET_ID,
                'scope_level' => 'digital_asset',
                'recommendation_id' => 'r-map-relevance',
                'owner' => 'Selin Kaya',
                'assignee_id' => 'u-selin',
                'priority' => 'medium',
                'due' => 'Last week',
                'status' => 'completed',
                'origin' => 'Recommendation',
                'description' => 'Audit categories, services, and photo freshness against competitor density.',
                'success_signal' => 'Avg map rank trending ≤ 5.0 within 28 days.',
                'why' => [
                    'finding' => 'GBP local visibility declined',
                    'recommendation' => 'Review local relevance for “implant ankara”',
                    'evidence' => 'Avg map rank 4.8 → 5.4',
                ],
                'do' => 'Update categories/services and refresh photos in GBP (outside MoxDOP).',
                'measure' => 'Avg map rank and Top3 share over the follow-up window.',
                'follow_up' => 'Compare next map-grid refresh against this baseline.',
                'outcome' => [
                    'status' => 'improvement_observed',
                    'label' => 'Improvement observed',
                    'before' => 'Avg map rank 5.4',
                    'after' => 'Avg map rank 5.1',
                    'period' => '28 days',
                    'note' => 'Not claiming causality — follow-up data only.',
                ],
            ],
            [
                'id' => 't-brand-positioning',
                'title' => 'Update positioning language for medical travel',
                'brand' => 'Atlas Dental Ankara',
                'customer' => 'Atlas Health Group',
                'asset' => null,
                'asset_type' => null,
                'digital_asset_id' => null,
                'scope_level' => 'brand',
                'recommendation_id' => null,
                'owner' => 'Ayşe Demir',
                'assignee_id' => 'u-ayse',
                'priority' => 'low',
                'due' => 'Next month',
                'status' => 'open',
                'origin' => 'Manual',
                'description' => 'Brand-scoped strategic task — not tied to a single Digital Asset (Demo scope illustration).',
                'success_signal' => 'Brand Context positioning updated and reviewed.',
                'why' => [
                    'finding' => null,
                    'recommendation' => null,
                    'evidence' => 'Operator-initiated Brand Context refinement',
                ],
                'do' => 'Revise Brand Intelligence Context positioning and differentiators.',
                'measure' => 'Brand Context completeness fields updated.',
                'follow_up' => 'No Outcome required — strategic documentation task.',
                'outcome' => null,
                'scope_note' => 'Demo illustrates Brand-scoped Task. Eloquent Task model still requires customer_id + brand_id + digital_asset_id in production — see product completeness report.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activitySeed(): array
    {
        return [
            [
                'id' => 'a1',
                'title' => 'Meta data import',
                'status' => 'running',
                'detail' => '11 / 31 accounts ready',
                'when' => 'Now',
                'link' => 'operator.integrations.meta',
                'link_label' => 'Open Meta import',
            ],
            [
                'id' => 'a2',
                'title' => 'Website technical scan',
                'status' => 'completed',
                'detail' => 'atlasdental.example',
                'when' => 'Today',
            ],
            [
                'id' => 'a3',
                'title' => 'GBP map grid refresh',
                'status' => 'completed',
                'detail' => 'implant ankara',
                'when' => 'Yesterday',
            ],
            [
                'id' => 'a4',
                'title' => 'Growth synthesis',
                'status' => 'completed',
                'detail' => 'Atlas Dental Ankara',
                'when' => 'Yesterday',
            ],
            [
                'id' => 'a5',
                'title' => 'Google Ads refresh',
                'status' => 'partial',
                'detail' => 'Search terms OK · one asset skipped',
                'when' => '2 days ago',
            ],
            [
                'id' => 'a6',
                'title' => 'Public brand research',
                'status' => 'queued',
                'detail' => 'Waiting',
                'when' => '—',
            ],
            [
                'id' => 'a7',
                'title' => 'Meta Insights report',
                'status' => 'needs_attention',
                'detail' => 'Sample Account · ads_read missing',
                'when' => '3 days ago',
                'link' => 'operator.integrations.meta',
                'link_label' => 'Open Meta import',
            ],
            [
                'id' => 'a8',
                'title' => 'Hosting probe',
                'status' => 'failed',
                'detail' => 'DemoHost timeout (demo)',
                'when' => '4 days ago',
            ],
        ];
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
                ['label' => 'Meta Ads', 'route' => 'operator.meta.overview'],
                ['label' => 'Website', 'route' => 'operator.website'],
                ['label' => 'Google Ads', 'route' => 'operator.google-ads.overview'],
                ['label' => 'GBP', 'route' => 'operator.gbp'],
            ],
            'priorities' => [
                'Replace underperforming Meta creative PB-Video-03.',
                'Fix landing-page mobile LCP on /implant.',
                'Add negatives for Irrelevant / Negative-candidate search terms.',
                'Review local relevance for “implant ankara” and clear review backlog.',
            ],
            'attention' => [
                'Meta CPL deteriorated while spend remained material on Post Bariatric — Europe.',
                'Website mobile LCP on /implant may amplify paid inefficiency.',
                'GBP north-east map visibility weakened for “implant ankara”.',
            ],
            'opportunities' => [
                'Google Ads remains comparatively stable — protect while fixing Meta/Website friction.',
                'GBP review velocity is healthy if unanswered reviews are cleared.',
            ],
            'cross_channel' => [
                'Website ↔ Google Ads landing-page message mismatch should be inspected together with Meta landing-page LCP.',
                'Do not treat Meta CPL recovery as independent from Website mobile performance.',
            ],
            'unknowns' => [
                'Instagram asset not configured — social consistency not evaluated.',
                'GA4 key-event verification depth is limited in demo data.',
            ],
            'sources_available' => [
                ['label' => 'Business Context', 'state' => 'available'],
                ['label' => 'Website', 'state' => 'available'],
                ['label' => 'Google Ads', 'state' => 'available'],
                ['label' => 'Meta Ads', 'state' => 'available'],
                ['label' => 'Google Business', 'state' => 'available'],
                ['label' => 'GA4', 'state' => 'available'],
                ['label' => 'Instagram', 'state' => 'not_connected'],
            ],
            'as_of' => 'Today 14:20',
            'risks' => [
                'Continuing spend on fatigued Meta creative without replacement.',
                'Paid traffic landing on a slow mobile page.',
                'Waste share remaining above 10% of Google Ads spend.',
            ],
            'disclaimer' => 'Demo guidance — no live model call. AI does not write to external systems. Interpretation is advisory, not automatic Recommendations.',
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
