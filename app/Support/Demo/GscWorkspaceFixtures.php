<?php

namespace App\Support\Demo;

use Carbon\Carbon;

/**
 * Deterministic Demo Mode fixtures for the Search Console Organic Demand workspace.
 * No live GSC API. No runtime randomness — crc32/hash only.
 *
 * Daily property series cover 90 days ending {@see DemoPeriod::ANCHOR_DATE}
 * so custom ranges aggregate honestly from day buckets.
 */
final class GscWorkspaceFixtures
{
    /** last_28 baseline clicks (property level). */
    private const int BASELINE_CLICKS = 18420;

    /** last_28 baseline impressions (property level). */
    private const int BASELINE_IMPRESSIONS = 842000;

    /** last_28 impression-weighted average position. */
    private const float BASELINE_POSITION = 8.4;

    /**
     * Page click share of last_28 baseline (must sum ~1.0).
     *
     * @var array<string, array{share: float, title: string, role: string, offering: ?string}>
     */
    private const array PAGES = [
        '/implant' => [
            'share' => 0.28,
            'title' => 'Dental Implants in Ankara',
            'role' => 'Service / Product',
            'offering' => 'Implant',
        ],
        '/' => [
            'share' => 0.18,
            'title' => 'Atlas Dental Ankara',
            'role' => 'Home',
            'offering' => null,
        ],
        '/post-bariatric' => [
            'share' => 0.14,
            'title' => 'Post-Bariatric Dentistry',
            'role' => 'Service / Product',
            'offering' => 'Post-Bariatric',
        ],
        '/contact' => [
            'share' => 0.10,
            'title' => 'Contact',
            'role' => 'Conversion / Contact',
            'offering' => null,
        ],
        '/team' => [
            'share' => 0.06,
            'title' => 'Our specialists',
            'role' => 'Team / Expert',
            'offering' => null,
        ],
        '/blog/implant-bakimi' => [
            'share' => 0.08,
            'title' => 'Implant bakımı rehberi',
            'role' => 'Content / Blog',
            'offering' => 'Implant',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function workspace(string $preset = 'last_28', ?string $start = null, ?string $end = null): array
    {
        $f = DemoCatalog::periodFactors($preset, $start, $end);
        $bounds = DemoPeriod::bounds($preset, $f['start'] ?? $start, $f['end'] ?? $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prev = DemoPeriod::previousBounds($preset, $rangeStart, $rangeEnd);

        $totals = self::aggregateProperty($rangeStart, $rangeEnd);
        $compare = self::aggregateProperty(
            $prev['start']->toDateString(),
            $prev['end']->toDateString(),
        );

        return [
            'period_label' => $f['label'],
            'period_days' => $bounds['days'],
            'period_start' => $rangeStart,
            'period_end' => $rangeEnd,
            'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'Demo Mode · product vision fixtures — no live Search Console API or write',
            'identity' => self::identity(),
            'freshness' => self::freshness(),
            'glance' => self::glance($totals, $compare, $f),
            'needs_attention' => self::needsAttention(),
            'performance_trend' => self::performanceTrend($rangeStart, $rangeEnd),
            'search_momentum' => self::searchMomentum($totals),
            'page_pulse' => self::pagePulse($totals),
            'discoverability' => self::discoverability(),
            'opportunities' => self::opportunities($totals),
            'recent_outcomes' => self::recentOutcomes(),
            'performance' => self::performance($totals),
            'demand' => self::demand($totals),
            'pages' => self::pagesDirectory($totals),
            'indexing' => self::indexing(),
            'relationships' => self::relationships(),
            'operations' => self::operations(),
            'narrative' => $f['narrative'] ?? null,
            'missing_note' => 'Missing ≠ zero — Unavailable means the signal is absent, not a measured 0. GSC cannot prove query→conversion.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'eyebrow' => 'Google Search Console',
            'title' => 'Atlas Dental — Search Console',
            'brand' => 'Atlas Dental Ankara',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'website_asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
            'ga4_asset_id' => DemoCatalog::GA4_ASSET_ID,
            'google_ads_asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
            'gbp_asset_id' => DemoCatalog::GBP_ASSET_ID,
            'gsc_asset_id' => DemoCatalog::GSC_ASSET_ID,
            'relationship_line' => 'Observes · Atlas Dental Website',
            'property_label' => 'sc-domain:atlasdental.example',
            'property_type' => 'Domain property',
            'status' => 'Connected',
            'freshness' => 'Data through Aug 11',
            'reporting_timezone' => DemoPeriod::TIMEZONE,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function freshness(): array
    {
        return [
            ['source' => 'Search Console', 'age' => '2h', 'detail' => 'Last successful collection · Aug 12 22:18 Europe/Berlin', 'state' => 'current'],
            ['source' => 'Website', 'age' => '4h', 'detail' => 'Content roles + page inventory · Demo', 'state' => 'current'],
            ['source' => 'GA4', 'age' => '2h', 'detail' => 'Landing sessions + mapped actions context', 'state' => 'current'],
            ['source' => 'Google Business Profile', 'age' => '2h', 'detail' => 'Local entity cross-check', 'state' => 'current'],
            ['source' => 'Brand Context', 'age' => 'Current', 'detail' => 'Operator maintained', 'state' => 'current'],
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int, ctr: float, avg_position: float}  $totals
     * @param  array{clicks: int, impressions: int, ctr: float, avg_position: float}  $compare
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function glance(array $totals, array $compare, array $f): array
    {
        $clicksDelta = self::pctDelta((int) $totals['clicks'], (int) $compare['clicks']);
        $impressionsDelta = self::pctDelta((int) $totals['impressions'], (int) $compare['impressions']);
        $ctrDeltaPp = round(((float) $totals['ctr'] - (float) $compare['ctr']) * 100, 1);
        $positionDelta = round((float) $totals['avg_position'] - (float) $compare['avg_position'], 1);

        return [
            'clicks' => [
                'value' => self::formatCompact((int) $totals['clicks']),
                'raw' => (int) $totals['clicks'],
                'secondary' => self::formatDelta($clicksDelta).' vs previous period · avg pos '.number_format((float) $totals['avg_position'], 1),
                'tone' => $clicksDelta >= 0 ? 'positive' : 'warning',
                'avg_position' => (float) $totals['avg_position'],
            ],
            'impressions' => [
                'value' => self::formatCompact((int) $totals['impressions']),
                'raw' => (int) $totals['impressions'],
                'secondary' => self::formatDelta($impressionsDelta).' · '.$f['label'],
                'tone' => $impressionsDelta >= 0 ? 'positive' : 'neutral',
            ],
            'ctr' => [
                'value' => number_format((float) $totals['ctr'] * 100, 2).'%',
                'raw' => (float) $totals['ctr'],
                'secondary' => ($ctrDeltaPp > 0 ? '+' : '').$ctrDeltaPp.'pp vs previous',
                'tone' => $ctrDeltaPp >= 0 ? 'positive' : 'warning',
            ],
            'search_attention' => [
                'value' => '48',
                'raw' => 48,
                'secondary' => 'Query clusters with material impressions · '.$f['label'],
                'tone' => 'neutral',
                'note' => 'Observed breadth — not a quality score.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function needsAttention(): array
    {
        return [
            [
                'id' => 'gsc-att-implant-visibility',
                'severity' => 'High',
                'title' => 'Implant Turkey / Ankara visibility decline',
                'metric' => 'Cluster clicks −14% · impressions +6%',
                'scope' => '/implant · implant ankara queries',
                'action' => 'Inspect',
                'tab' => 'demand',
                'finding_id' => 'gsc-f-implant-visibility',
            ],
            [
                'id' => 'gsc-att-indexing',
                'severity' => 'High',
                'title' => 'Indexing review on priority URLs',
                'metric' => 'Canonical mismatch on /implant · sitemap gap',
                'scope' => 'Google index state · Website',
                'action' => 'Review',
                'tab' => 'indexing',
                'finding_id' => 'gsc-f-index-canonical',
            ],
            [
                'id' => 'gsc-att-ctr',
                'severity' => 'Medium',
                'title' => 'CTR opportunity on price/cost queries',
                'metric' => 'diş implantı fiyat · 22k impr · 1.9% CTR',
                'scope' => 'Price / cost cluster',
                'action' => 'Inspect',
                'tab' => 'demand',
                'finding_id' => 'gsc-f-price-ctr',
            ],
            [
                'id' => 'gsc-att-ownership',
                'severity' => 'Medium',
                'title' => 'Ownership fragmented across implant URLs',
                'metric' => '/implant · / · /blog/implant-bakimi',
                'scope' => 'Implant cluster',
                'action' => 'Review',
                'tab' => 'demand',
                'finding_id' => 'gsc-f-ownership-fragmented',
            ],
            [
                'id' => 'gsc-att-freshness',
                'severity' => 'Low',
                'title' => 'Collection freshness lag on Aug 11 boundary',
                'metric' => 'Data through Aug 11 · 2h ago',
                'scope' => 'Property',
                'action' => 'Note',
                'tab' => 'overview',
                'finding_id' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function performanceTrend(string $start, string $end): array
    {
        $series = self::metricSeries($start, $end);

        return [
            'labels' => $series['labels'],
            'clicks' => $series['clicks'],
            'impressions' => $series['impressions'],
            'note' => 'Clicks and impressions · daily Demo fixtures',
        ];
    }

    /**
     * Chart switcher series for clicks / impressions / ctr / position.
     *
     * @return array{labels: list<string>, clicks: list<int>, impressions: list<int>, ctr: list<float>, position: list<float>}
     */
    public static function metricSeries(string $start, string $end): array
    {
        $days = self::daysInRange($start, $end);
        $labels = [];
        $clicks = [];
        $impressions = [];
        $ctr = [];
        $position = [];

        foreach ($days as $date) {
            $day = self::scaledDay($date);
            $labels[] = Carbon::parse($date, DemoPeriod::TIMEZONE)->format('M j');
            $clicks[] = $day['clicks'];
            $impressions[] = $day['impressions'];
            $ctr[] = $day['impressions'] > 0
                ? round($day['clicks'] / $day['impressions'], 4)
                : 0.0;
            $position[] = $day['avg_position'];
        }

        if (count($labels) > 42) {
            $step = (int) ceil(count($labels) / 28);
            $sampled = ['labels' => [], 'clicks' => [], 'impressions' => [], 'ctr' => [], 'position' => []];
            foreach ($labels as $i => $label) {
                if ($i % $step !== 0) {
                    continue;
                }
                $sampled['labels'][] = $label;
                $sampled['clicks'][] = $clicks[$i];
                $sampled['impressions'][] = $impressions[$i];
                $sampled['ctr'][] = $ctr[$i];
                $sampled['position'][] = $position[$i];
            }

            return $sampled;
        }

        return [
            'labels' => $labels,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $position,
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int}  $totals
     * @return array<string, int>
     */
    public static function searchMomentum(array $totals): array
    {
        $clicks = (int) $totals['clicks'];

        return [
            'growing' => 12,
            'new' => 4,
            'declining' => 9,
            'lost' => 2,
            'ctr_review' => 6,
            'striking_distance' => 5,
            'note' => 'Heuristic cluster momentum · relative to prior comparable window',
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function pagePulse(array $totals): array
    {
        $clicks = (int) $totals['clicks'];
        $rows = [];

        foreach (self::PAGES as $path => $meta) {
            $pageClicks = (int) round($clicks * $meta['share']);
            $rows[] = [
                'path' => $path,
                'title' => $meta['title'],
                'clicks' => $pageClicks,
                'trend' => match ($path) {
                    '/implant' => 'declining',
                    '/blog/implant-bakimi' => 'growing',
                    '/post-bariatric' => 'stable',
                    default => 'stable',
                },
                'clusters' => match ($path) {
                    '/implant' => ['Implant Turkey / Ankara', 'Price / cost'],
                    '/blog/implant-bakimi' => ['Implant recovery', 'Ownership fragmented'],
                    '/' => ['Brand Atlas Dental', 'Ownership fragmented'],
                    '/contact' => ['Local çankaya diş'],
                    default => [],
                },
                'state' => match ($path) {
                    '/implant' => 'Canonical review · LCP Finding on Website',
                    '/blog/implant-bakimi' => 'Indexed · low impressions',
                    default => null,
                },
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function discoverability(): array
    {
        return [
            'subtitle' => 'How Website URLs progress toward observed search outcomes — not a ranking score.',
            'stages' => [
                ['stage' => 'Website URLs', 'count' => 62, 'provenance' => 'Website'],
                ['stage' => 'Sitemap discovered', 'count' => 60, 'provenance' => 'Sitemap'],
                ['stage' => 'Index observed', 'count' => 57, 'provenance' => 'Google index state'],
                ['stage' => 'Impressions', 'count' => 48, 'provenance' => 'Search Console'],
                ['stage' => 'Clicks', 'count' => 31, 'provenance' => 'Search Console'],
                ['stage' => 'GA4 mapped actions', 'count' => 19, 'provenance' => 'GA4 · page-level only'],
            ],
            'note' => 'GA4 actions are page-attributed — GSC cannot prove query→conversion.',
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function opportunities(array $totals): array
    {
        return [
            [
                'priority' => 'High',
                'title' => 'Recover implant Ankara visibility',
                'metric' => 'Cluster declining · /implant primary',
                'cta' => 'Open demand',
                'tab' => 'demand',
            ],
            [
                'priority' => 'High',
                'title' => 'Resolve canonical mismatch on /implant',
                'metric' => 'Google index state · Website Finding',
                'cta' => 'Open indexing',
                'tab' => 'indexing',
            ],
            [
                'priority' => 'Medium',
                'title' => 'Improve CTR on price/cost queries',
                'metric' => '1.9% CTR · 22k impressions',
                'cta' => 'Open demand',
                'tab' => 'demand',
            ],
            [
                'priority' => 'Medium',
                'title' => 'Consolidate implant URL ownership',
                'metric' => '3 URLs competing in cluster',
                'cta' => 'Open demand',
                'tab' => 'demand',
            ],
            [
                'priority' => 'Explore',
                'title' => 'Expand implant recovery content',
                'metric' => 'Growing cluster · blog opportunity',
                'cta' => 'Open pages',
                'tab' => 'pages',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentOutcomes(): array
    {
        return [
            [
                'title' => 'Canonical template remediation on service pages',
                'state' => 'Improvement observed',
                'note' => 'Google index state improved on 4 URLs — not attributed as causal',
            ],
            [
                'title' => 'Implant recovery blog publish',
                'state' => 'Still observed',
                'note' => 'Cluster growing but impressions still thin vs /implant',
            ],
            [
                'title' => 'Ownership consolidation review',
                'state' => 'Insufficient evidence',
                'note' => 'Fragmentation persists across 3 implant-related URLs',
            ],
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int, ctr: float, avg_position: float}  $totals
     * @return array<string, mixed>
     */
    public static function performance(array $totals): array
    {
        $clicks = (int) $totals['clicks'];
        $impressions = (int) $totals['impressions'];

        return [
            'devices' => [
                ['device' => 'Mobile', 'clicks' => (int) round($clicks * 0.63), 'impressions' => (int) round($impressions * 0.58), 'ctr' => 2.4, 'position' => 9.8],
                ['device' => 'Desktop', 'clicks' => (int) round($clicks * 0.32), 'impressions' => (int) round($impressions * 0.35), 'ctr' => 2.0, 'position' => 7.1],
                ['device' => 'Tablet', 'clicks' => (int) round($clicks * 0.05), 'impressions' => (int) round($impressions * 0.07), 'ctr' => 1.6, 'position' => 10.4],
            ],
            'countries' => [
                ['country' => 'Türkiye', 'clicks' => (int) round($clicks * 0.78), 'impressions' => (int) round($impressions * 0.72)],
                ['country' => 'Germany', 'clicks' => (int) round($clicks * 0.08), 'impressions' => (int) round($impressions * 0.10)],
                ['country' => 'United Kingdom', 'clicks' => (int) round($clicks * 0.06), 'impressions' => (int) round($impressions * 0.08)],
                ['country' => 'Netherlands', 'clicks' => (int) round($clicks * 0.03), 'impressions' => (int) round($impressions * 0.04)],
            ],
            'brand_nonbrand' => [
                'source' => 'Derived',
                'brand' => ['clicks' => (int) round($clicks * 0.14), 'impressions' => (int) round($impressions * 0.03), 'ctr' => 10.2, 'position' => 1.8],
                'nonbrand' => ['clicks' => (int) round($clicks * 0.86), 'impressions' => (int) round($impressions * 0.97), 'ctr' => 1.9, 'position' => 9.6],
                'note' => 'Brand classification heuristic · Demo fixtures',
            ],
            'diagnosis' => [
                'interpretation' => 'Impressions grew while clicks softened — CTR pressure concentrated on implant and price-intent clusters. Mobile carries more impressions at weaker positions.',
                'source' => 'Derived · Search Console aggregates',
                'disclaimer' => 'Interpretation only — not a ranking or quality score.',
            ],
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int, ctr: float, avg_position: float}  $totals
     * @return array<string, mixed>
     */
    public static function demand(array $totals): array
    {
        $clicks = (int) $totals['clicks'];
        $impressions = (int) $totals['impressions'];

        $clusters = [
            [
                'id' => 'cl-implant-tr-ankara',
                'name' => 'Implant Turkey / Implant Ankara',
                'clicks' => (int) round($clicks * 0.22),
                'impressions' => (int) round($impressions * 0.18),
                'ctr' => 2.4,
                'position' => 9.8,
                'trend' => 'declining',
                'intent' => 'Treatment / high intent',
                'primary_page' => '/implant',
                'ownership_state' => 'Primary URL under pressure',
                'queries' => ['implant ankara', 'dental implants turkey', 'implant türkiye'],
            ],
            [
                'id' => 'cl-implant-recovery',
                'name' => 'Implant recovery',
                'clicks' => (int) round($clicks * 0.06),
                'impressions' => (int) round($impressions * 0.05),
                'ctr' => 2.1,
                'position' => 11.4,
                'trend' => 'growing',
                'intent' => 'Informational → consideration',
                'primary_page' => '/blog/implant-bakimi',
                'ownership_state' => 'Blog gaining traction',
                'queries' => ['implant bakımı', 'implant sonrası bakım', 'implant recovery tips'],
            ],
            [
                'id' => 'cl-brand',
                'name' => 'Brand Atlas Dental',
                'clicks' => (int) round($clicks * 0.14),
                'impressions' => (int) round($impressions * 0.03),
                'ctr' => 10.2,
                'position' => 1.8,
                'trend' => 'stable',
                'intent' => 'Brand',
                'primary_page' => '/',
                'ownership_state' => 'Healthy',
                'queries' => ['atlas dental', 'atlas dental ankara', 'atlas diş kliniği'],
            ],
            [
                'id' => 'cl-local-cankaya',
                'name' => 'Local çankaya diş',
                'clicks' => (int) round($clicks * 0.08),
                'impressions' => (int) round($impressions * 0.06),
                'ctr' => 3.8,
                'position' => 8.6,
                'trend' => 'growing',
                'intent' => 'Local',
                'primary_page' => '/contact',
                'ownership_state' => 'GBP cross-asset alignment',
                'queries' => ['çankaya diş kliniği', 'çankaya implant', 'ankara çankaya diş'],
                'gbp_asset_id' => DemoCatalog::GBP_ASSET_ID,
            ],
            [
                'id' => 'cl-price-cost',
                'name' => 'Price / cost CTR review',
                'clicks' => (int) round($clicks * 0.11),
                'impressions' => (int) round($impressions * 0.14),
                'ctr' => 1.9,
                'position' => 14.2,
                'trend' => 'ctr_review',
                'intent' => 'Price research',
                'primary_page' => '/implant',
                'ownership_state' => 'Snippet opportunity',
                'queries' => ['diş implantı fiyat', 'implant fiyat ankara', 'implant maliyeti'],
            ],
            [
                'id' => 'cl-ownership-fragmented',
                'name' => 'Ownership fragmented (implant)',
                'clicks' => (int) round($clicks * 0.09),
                'impressions' => (int) round($impressions * 0.08),
                'ctr' => 2.0,
                'position' => 10.1,
                'trend' => 'declining',
                'intent' => 'Mixed',
                'primary_page' => '/implant',
                'ownership_state' => 'Fragmented across /implant, /, /blog/implant-bakimi',
                'queries' => ['implant ankara fiyat', 'implant bakımı nasıl', 'atlas dental implant'],
            ],
        ];

        return [
            'clusters' => $clusters,
            'queries' => self::queriesExplorer($totals),
            'momentum' => [
                'growing' => ['implant bakımı', 'çankaya diş kliniği', 'post bariatric dental ankara'],
                'declining' => ['implant ankara', 'dental implants turkey', 'implant türkiye fiyat'],
                'new' => ['implant sonrası beslenme', 'zirkonyum implant ankara'],
                'lost' => ['atlas dental implant fiyat listesi'],
                'ctr_review' => ['diş implantı fiyat', 'implant fiyat ankara'],
                'striking_distance' => ['implant ankara çankaya', 'en iyi implant kliniği ankara'],
            ],
            'ownership_reviews' => [
                [
                    'cluster' => 'Implant Turkey / Implant Ankara',
                    'urls' => ['/implant', '/', '/blog/implant-bakimi'],
                    'state' => 'Fragmented',
                    'recommendation' => 'Clarify primary service URL and internal linking — internal review only.',
                ],
            ],
        ];
    }

    /**
     * @param  array{clicks: int, impressions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function queriesExplorer(array $totals): array
    {
        $rf = max(0.05, (int) $totals['clicks'] / self::BASELINE_CLICKS);

        return [
            ['query' => 'implant ankara', 'clicks' => (int) round(920 * $rf), 'impressions' => (int) round(18000 * $rf), 'ctr' => 5.1, 'position' => 8.2, 'page' => '/implant', 'trend' => 'declining'],
            ['query' => 'diş implantı fiyat', 'clicks' => (int) round(410 * $rf), 'impressions' => (int) round(22000 * $rf), 'ctr' => 1.9, 'position' => 18.0, 'page' => '/implant', 'trend' => 'ctr_review'],
            ['query' => 'atlas dental', 'clicks' => (int) round(380 * $rf), 'impressions' => (int) round(2100 * $rf), 'ctr' => 18.1, 'position' => 1.4, 'page' => '/', 'trend' => 'stable'],
            ['query' => 'çankaya diş kliniği', 'clicks' => (int) round(290 * $rf), 'impressions' => (int) round(6400 * $rf), 'ctr' => 4.5, 'position' => 9.1, 'page' => '/contact', 'trend' => 'growing'],
            ['query' => 'implant bakımı', 'clicks' => (int) round(180 * $rf), 'impressions' => (int) round(4200 * $rf), 'ctr' => 4.3, 'position' => 11.8, 'page' => '/blog/implant-bakimi', 'trend' => 'growing'],
            ['query' => 'post bariatric dental ankara', 'clicks' => (int) round(140 * $rf), 'impressions' => (int) round(3100 * $rf), 'ctr' => 4.5, 'position' => 10.4, 'page' => '/post-bariatric', 'trend' => 'stable'],
        ];
    }

    /**
     * @param  array{clicks: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function pagesDirectory(array $totals): array
    {
        $clicks = (int) $totals['clicks'];
        $rows = [];

        foreach (self::PAGES as $path => $meta) {
            $pageClicks = (int) round($clicks * $meta['share']);
            $sessions = (int) round($pageClicks * 2.6);
            $rows[] = [
                'path' => $path,
                'title' => $meta['title'],
                'content_role' => $meta['role'],
                'offering' => $meta['offering'],
                'clicks' => $pageClicks,
                'ga4_context' => [
                    'sessions' => $sessions,
                    'engagement_rate' => match ($path) {
                        '/contact' => 72,
                        '/post-bariatric' => 61,
                        '/implant' => 54,
                        default => 49,
                    },
                    'mapped_actions' => match ($path) {
                        '/contact' => (int) round($sessions * 0.015),
                        '/implant' => (int) round($sessions * 0.014),
                        '/post-bariatric' => (int) round($sessions * 0.013),
                        default => (int) round($sessions * 0.006),
                    },
                    'note' => 'Page-level GA4 — not query-attributed',
                ],
                'website_attention' => match ($path) {
                    '/implant' => 'Mobile LCP Finding',
                    '/contact' => 'Local phone consistency Finding',
                    default => null,
                },
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function indexing(): array
    {
        return [
            'subtitle' => 'Google index state and sitemap reconciliation — read-only observation.',
            'coverage' => [
                'indexed' => 57,
                'not_indexed' => 5,
                'unknown' => 3,
                'excluded' => 8,
            ],
            'urls' => [
                ['url' => 'https://atlasdental.example/', 'state' => 'Indexed', 'impressions' => 'Observed', 'note' => 'Healthy'],
                ['url' => 'https://atlasdental.example/implant', 'state' => 'Indexed', 'impressions' => 'Observed', 'note' => 'Canonical mismatch candidate · Website Finding'],
                ['url' => 'https://atlasdental.example/post-bariatric', 'state' => 'Indexed', 'impressions' => 'Observed', 'note' => 'Healthy'],
                ['url' => 'https://atlasdental.example/team', 'state' => 'Indexed', 'impressions' => 'Low', 'note' => 'Indexed · thin impressions'],
                ['url' => 'https://atlasdental.example/blog/implant-bakimi', 'state' => 'Indexed', 'impressions' => 'Growing', 'note' => 'Recovery cluster'],
                ['url' => 'https://atlasdental.example/contact', 'state' => 'Indexed', 'impressions' => 'Observed', 'note' => 'Local cluster landing'],
            ],
            'sitemaps' => [
                ['path' => '/sitemap.xml', 'submitted' => '01 Aug 2026', 'discovered' => 60, 'status' => 'Success'],
                ['path' => '/sitemap-blog.xml', 'submitted' => '01 Aug 2026', 'discovered' => 8, 'status' => 'Success'],
                ['path' => '/sitemap-priority.xml', 'submitted' => '—', 'discovered' => 0, 'status' => 'Not submitted', 'note' => 'One priority URL missing from sitemap'],
            ],
            'reconciliation' => [
                'website_urls' => 62,
                'sitemap_urls' => 60,
                'index_observed' => 57,
                'gaps' => [
                    '2 URLs on Website not in sitemap',
                    '3 indexed URLs with no impressions in current window',
                ],
            ],
            'discoverability_by_role' => [
                ['role' => 'Service / Product', 'indexed' => 18, 'with_impressions' => 14],
                ['role' => 'Home', 'indexed' => 1, 'with_impressions' => 1],
                ['role' => 'Conversion / Contact', 'indexed' => 4, 'with_impressions' => 3],
                ['role' => 'Content / Blog', 'indexed' => 12, 'with_impressions' => 6],
                ['role' => 'Team / Expert', 'indexed' => 3, 'with_impressions' => 1],
            ],
            'inspection_samples' => [
                ['url' => 'https://atlasdental.example/implant', 'coverage' => 'Indexed', 'canonical' => 'Mismatch candidate', 'last_crawl' => '10 Aug 2026'],
                ['url' => 'https://atlasdental.example/blog/implant-bakimi', 'coverage' => 'Indexed', 'canonical' => 'Self', 'last_crawl' => '09 Aug 2026'],
                ['url' => 'https://atlasdental.example/team', 'coverage' => 'Indexed', 'canonical' => 'Self', 'last_crawl' => '08 Aug 2026', 'impressions' => 'None in window'],
                ['url' => 'https://atlasdental.example/old-landing', 'coverage' => 'Unknown to Google', 'canonical' => '—', 'last_crawl' => '—'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function relationships(): array
    {
        return [
            'observes' => [
                [
                    'asset' => 'Atlas Dental Website',
                    'asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
                    'relationship' => 'Observes',
                    'detail' => 'Search visibility for atlasdental.example domain property',
                    'route' => 'demo.website',
                ],
            ],
            'provides_evidence_to' => [
                [
                    'asset' => 'Google Ads',
                    'asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                    'detail' => 'Organic demand context for paid search overlap review',
                    'route' => 'demo.google-ads.overview',
                ],
                [
                    'asset' => 'Google Business Profile',
                    'asset_id' => DemoCatalog::GBP_ASSET_ID,
                    'detail' => 'Local query alignment · entity consistency',
                    'route' => 'demo.gbp',
                ],
                [
                    'asset' => 'Google Analytics (GA4)',
                    'asset_id' => DemoCatalog::GA4_ASSET_ID,
                    'detail' => 'Page-level sessions and mapped actions — not query-attributed',
                    'route' => 'demo.analytics',
                ],
            ],
            'technical_connection' => [
                'type' => 'Search Console domain property binding',
                'property' => 'sc-domain:atlasdental.example',
                'property_type' => 'Domain property',
                'status' => 'Connected',
                'note' => 'Demo Mode · read-only · no live credentials',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function operations(): array
    {
        return [
            'subtitle' => 'Search findings, decisions, work and observed outcomes — no causal claims.',
            'findings' => [
                ['id' => 'gsc-f-implant-visibility', 'severity' => 'high', 'category' => 'Visibility', 'title' => 'Implant Turkey / Ankara cluster visibility decline', 'status' => 'open'],
                ['id' => 'gsc-f-index-canonical', 'severity' => 'high', 'category' => 'Indexing', 'title' => 'Canonical mismatch candidate on /implant', 'status' => 'open'],
                ['id' => 'gsc-f-price-ctr', 'severity' => 'medium', 'category' => 'CTR', 'title' => 'Price/cost queries earn impressions with weak CTR', 'status' => 'open'],
                ['id' => 'gsc-f-ownership-fragmented', 'severity' => 'medium', 'category' => 'Ownership', 'title' => 'Implant cluster ownership fragmented across 3 URLs', 'status' => 'open'],
            ],
            'recommendations' => [
                ['id' => 'gsc-r-canonical', 'title' => 'Align canonical template on /implant with Website primary URL', 'finding_id' => 'gsc-f-index-canonical', 'status' => 'accepted'],
                ['id' => 'gsc-r-ownership', 'title' => 'Review internal linking for implant cluster primary URL', 'finding_id' => 'gsc-f-ownership-fragmented', 'status' => 'pending'],
                ['id' => 'gsc-r-ctr', 'title' => 'Review title/meta for price-intent implant queries', 'finding_id' => 'gsc-f-price-ctr', 'status' => 'pending'],
            ],
            'tasks' => [
                ['id' => 'gsc-t-canonical', 'title' => 'Developer: fix canonical template on service pages', 'status' => 'completed', 'owner' => 'Website developer', 'due' => '8 Aug'],
                ['id' => 'gsc-t-blog', 'title' => 'Publish implant recovery blog content', 'status' => 'completed', 'owner' => 'Content', 'due' => '5 Aug'],
                ['id' => 'gsc-t-ownership', 'title' => 'Internal review: implant URL ownership map', 'status' => 'open', 'owner' => 'SEO lead', 'due' => '16 Aug'],
            ],
            'outcomes' => [
                [
                    'task' => 'Developer: fix canonical template on service pages',
                    'state' => 'Improvement observed',
                    'note' => 'Google index state improved on 4 URLs after Website deploy — correlation only, not causal proof.',
                ],
                [
                    'task' => 'Publish implant recovery blog content',
                    'state' => 'Improvement observed',
                    'note' => 'Implant recovery cluster impressions grew; clicks still modest vs /implant.',
                ],
                [
                    'task' => 'Internal review: implant URL ownership map',
                    'state' => 'Still observed',
                    'note' => 'Fragmentation across /implant, /, /blog/implant-bakimi remains in current window.',
                ],
            ],
            'finding_detail' => [
                'gsc-f-implant-visibility' => [
                    'what' => 'Implant Turkey / Ankara cluster shows declining clicks with rising impressions.',
                    'why' => 'Visibility without click recovery suggests SERP or snippet pressure on the primary service URL.',
                    'scope' => '/implant · implant ankara queries',
                    'evidence' => 'Search Console cluster aggregates · Demo daily fixtures',
                    'next' => 'Review with Website LCP Finding and canonical state — internal interpretation only.',
                ],
                'gsc-f-index-canonical' => [
                    'what' => 'Google index state on /implant shows canonical mismatch candidate aligned with Website Finding.',
                    'why' => 'Index clarity affects which URL earns impressions for implant queries.',
                    'scope' => '/implant · Google index state',
                    'evidence' => 'Search Console URL samples · Website diagnosis',
                    'next' => 'Complete canonical template remediation; re-observe in later collection windows.',
                    'outcome' => 'Improvement observed after developer Task — retained as history.',
                ],
                'gsc-f-price-ctr' => [
                    'what' => 'Price/cost queries earn material impressions with CTR below cluster median.',
                    'why' => 'Snippet and title alignment may be suppressing clicks despite visibility.',
                    'scope' => 'diş implantı fiyat · implant fiyat ankara',
                    'evidence' => 'Search Console query table · Demo fixtures',
                    'next' => 'Review on-page titles and meta — no external write from MoxDOP.',
                ],
                'gsc-f-ownership-fragmented' => [
                    'what' => 'Implant-related queries land on /implant, /, and /blog/implant-bakimi without clear primary ownership.',
                    'why' => 'Fragmented ownership dilutes cluster momentum and complicates operator decisions.',
                    'scope' => 'Implant cluster · 3 URLs',
                    'evidence' => 'Search Console page + query mapping · internal heuristic',
                    'next' => 'Complete ownership review Task; align internal linking on Website.',
                    'outcome' => 'Still observed — consolidation not yet evidenced.',
                ],
            ],
        ];
    }

    /**
     * Deterministic daily weight for property-level metrics (normalized via aggregate).
     *
     * @return array{clicks: float, impressions: float, position: float}
     */
    public static function rawDayWeight(string $date): array
    {
        $hash = crc32($date.'|gsc-atlas|demo');
        $unit = ($hash % 10000) / 10000;
        $dow = (int) Carbon::parse($date, DemoPeriod::TIMEZONE)->dayOfWeekIso;
        $weekend = $dow >= 6 ? 0.88 : 1.0;
        $wave = 0.82 + 0.38 * abs(sin(($hash % 360) * M_PI / 180));

        $anchor = DemoPeriod::anchor()->toDateString();
        $baselineStart = DemoPeriod::anchor()->copy()->subDays(27)->toDateString();
        $inCurrentWindow = $date >= $baselineStart && $date <= $anchor;

        // Prior window carries higher clicks / lower impressions so last_28 glance ≈ clicks −6%, impressions +9%.
        $clickTemporal = $inCurrentWindow ? 1.0 : 1.052;
        $impressionTemporal = $inCurrentWindow ? 1.0 : 0.898;

        $positionBase = 8.2 + (($hash % 120) / 100);
        if ($inCurrentWindow) {
            $positionBase += 0.15;
        }

        return [
            'clicks' => max(0.12, (0.40 + $unit * 0.95) * $weekend * $wave * $clickTemporal),
            'impressions' => max(0.18, (0.55 + $unit) * $weekend * $wave * $impressionTemporal),
            'position' => max(4.0, $positionBase * ($inCurrentWindow ? 1.02 : 0.98)),
        ];
    }

    /**
     * Aggregate property metrics for an inclusive date range, scaled to last_28 baselines.
     *
     * @return array{clicks: int, impressions: int, ctr: float, avg_position: float}
     */
    public static function aggregateProperty(string $start, string $end): array
    {
        $anchor = DemoPeriod::anchor();
        $baselineStart = $anchor->copy()->subDays(27)->toDateString();
        $baselineEnd = $anchor->toDateString();

        $baselineWeights = self::sumWeights($baselineStart, $baselineEnd);
        $rangeWeights = self::sumWeights($start, $end);

        $scale = static function (float $baselineTotal, float $rangeTotal, int $baselineValue): int {
            if ($baselineTotal <= 0.0) {
                return 0;
            }

            return (int) max(0, round($baselineValue * ($rangeTotal / $baselineTotal)));
        };

        $clicks = $scale($baselineWeights['clicks'], $rangeWeights['clicks'], self::BASELINE_CLICKS);
        $impressions = $scale($baselineWeights['impressions'], $rangeWeights['impressions'], self::BASELINE_IMPRESSIONS);

        $positionNumerator = 0.0;
        $positionDenominator = 0.0;
        foreach (self::daysInRange($start, $end) as $date) {
            $w = self::rawDayWeight($date);
            $dayImpressions = $w['impressions'];
            $positionNumerator += $w['position'] * $dayImpressions;
            $positionDenominator += $dayImpressions;
        }

        $baselinePositionNumerator = 0.0;
        $baselinePositionDenominator = 0.0;
        foreach (self::daysInRange($baselineStart, $baselineEnd) as $date) {
            $w = self::rawDayWeight($date);
            $baselinePositionNumerator += $w['position'] * $w['impressions'];
            $baselinePositionDenominator += $w['impressions'];
        }

        $avgPosition = $positionDenominator > 0.0
            ? round($positionNumerator / $positionDenominator, 1)
            : self::BASELINE_POSITION;

        if ($start === $baselineStart && $end === $baselineEnd) {
            $clicks = self::BASELINE_CLICKS;
            $impressions = self::BASELINE_IMPRESSIONS;
            $avgPosition = self::BASELINE_POSITION;
        }

        $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'avg_position' => $avgPosition,
        ];
    }

    /**
     * @return array{clicks: float, impressions: float, position: float}
     */
    private static function sumWeights(string $start, string $end): array
    {
        $sum = ['clicks' => 0.0, 'impressions' => 0.0, 'position' => 0.0];
        foreach (self::daysInRange($start, $end) as $date) {
            $w = self::rawDayWeight($date);
            $sum['clicks'] += $w['clicks'];
            $sum['impressions'] += $w['impressions'];
            $sum['position'] += $w['position'];
        }

        return $sum;
    }

    /**
     * @return array{clicks: int, impressions: int, avg_position: float}
     */
    private static function scaledDay(string $date): array
    {
        $anchor = DemoPeriod::anchor();
        $baselineStart = $anchor->copy()->subDays(27)->toDateString();
        $baselineEnd = $anchor->toDateString();
        $baselineWeights = self::sumWeights($baselineStart, $baselineEnd);
        $w = self::rawDayWeight($date);

        $scale = static function (float $dayWeight, float $baselineTotal, int $baselineValue): int {
            if ($baselineTotal <= 0.0) {
                return 0;
            }

            return (int) max(0, round($baselineValue * ($dayWeight / $baselineTotal)));
        };

        return [
            'clicks' => $scale($w['clicks'], $baselineWeights['clicks'], self::BASELINE_CLICKS),
            'impressions' => $scale($w['impressions'], $baselineWeights['impressions'], self::BASELINE_IMPRESSIONS),
            'avg_position' => round($w['position'], 1),
        ];
    }

    /**
     * @return list<string>
     */
    public static function daysInRange(string $start, string $end): array
    {
        $from = Carbon::parse($start, DemoPeriod::TIMEZONE)->startOfDay();
        $to = Carbon::parse($end, DemoPeriod::TIMEZONE)->startOfDay();
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $earliest = DemoPeriod::anchor()->copy()->subDays(89);
        if ($from->lessThan($earliest)) {
            $from = $earliest->copy();
        }
        if ($to->greaterThan(DemoPeriod::anchor())) {
            $to = DemoPeriod::anchor()->copy();
        }

        $days = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $days[] = $cursor->toDateString();
        }

        return $days;
    }

    public static function pctDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public static function formatDelta(float $delta): string
    {
        $prefix = $delta > 0 ? '+' : '';

        return $prefix.number_format($delta, 1).'%';
    }

    public static function formatCompact(int $value): string
    {
        if ($value >= 1_000_000) {
            return rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.').'M';
        }

        if ($value >= 10_000) {
            return rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.').'K';
        }

        if ($value >= 1_000) {
            return number_format($value);
        }

        return (string) $value;
    }
}
