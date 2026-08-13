<?php

namespace App\Support\Demo;

/**
 * Deterministic Demo Mode fixtures for the Google Ads Paid Acquisition workspace.
 * No live Google Ads API expansion or external writes.
 */
final class GoogleAdsWorkspaceFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function workspace(string $preset = 'last_28'): array
    {
        $f = DemoCatalog::periodFactors($preset);
        $campaigns = self::campaigns($f);
        $spend = (int) array_sum(array_column($campaigns, 'spend'));
        $leads = (int) array_sum(array_column($campaigns, 'leads'));
        $cpa = $leads > 0 ? (int) round($spend / $leads) : null;

        return [
            'period_label' => $f['label'],
            'demo_boundary' => 'Demo Mode · product vision fixtures — no live Google Ads write or API expansion',
            'identity' => self::identity(),
            'business_goal' => [
                'goal' => 'Qualified treatment enquiry',
                'primary_conversion' => 'Lead form submission',
            ],
            'freshness' => [
                ['source' => 'Google Ads', 'age' => '2h', 'detail' => 'Last successful collection: Aug 13 00:42'],
                ['source' => 'Website', 'age' => '4h', 'detail' => 'Website diagnosis Demo'],
                ['source' => 'GA4', 'age' => '2h', 'detail' => 'Measured Website behavior'],
                ['source' => 'Brand Context', 'age' => 'Current', 'detail' => 'Operator maintained'],
            ],
            'glance' => self::glance($spend, $leads, $cpa, $f),
            'pacing' => self::pacing($spend),
            'needs_attention' => self::needsAttention(),
            'performance_trend' => self::performanceTrend($f),
            'campaigns' => $campaigns,
            'spend_by_offering' => self::spendByOffering($campaigns),
            'search' => self::search($f),
            'ads' => self::ads($f),
            'landing_pages' => self::landingPages($f),
            'measurement' => self::measurement(),
            'operations' => self::operations(),
            'opportunities' => self::opportunities(),
            'recent_outcomes' => self::recentOutcomes(),
            'conversion_lag_note' => 'Recent conversion data may still be incomplete.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'eyebrow' => 'Google Ads',
            'title' => 'Atlas Dental — Europe',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'website_asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
            'strategy_line' => 'United Kingdom · English · Lead acquisition',
            'status' => 'Connected',
            'freshness' => 'Data through Aug 12',
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function glance(int $spend, int $leads, ?int $cpa, array $f): array
    {
        return [
            'spend' => [
                'value' => '₺'.number_format($spend),
                'secondary' => '+8% vs previous '.$f['label'],
                'tone' => 'neutral',
            ],
            'conversions' => [
                'value' => (string) $leads,
                'secondary' => 'Measurement healthy · Lead form',
                'tone' => 'positive',
            ],
            'cpa' => [
                'value' => $cpa !== null ? '₺'.number_format($cpa) : 'CPA unavailable',
                'secondary' => $cpa !== null ? '+6% vs previous '.$f['label'] : 'Primary mapping required',
                'tone' => $cpa !== null ? 'warning' : 'neutral',
            ],
            'pacing' => [
                'value' => 'Ahead of plan',
                'secondary' => '78% spent · 61% month elapsed',
                'tone' => 'warning',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pacing(int $spend): array
    {
        $planned = 60000;
        $elapsedPct = 61;
        $expected = (int) round($planned * ($elapsedPct / 100));
        $spendPct = (int) round(($spend / $planned) * 100);
        $ahead = max(0, $spend - $expected);

        return [
            'source' => 'Agency planned budget · operator context',
            'monthly_budget' => $planned,
            'elapsed_pct' => $elapsedPct,
            'expected_spend' => $expected,
            'actual_spend' => $spend,
            'spend_pct' => $spendPct,
            'remaining' => max(0, $planned - $spend),
            'ahead_by' => $ahead,
            'state' => 'Ahead of plan',
            'projected' => (int) round($spend / max(0.01, $elapsedPct / 100)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function needsAttention(): array
    {
        return [
            [
                'id' => 'att-measurement',
                'severity' => 'Critical',
                'title' => 'Measurement interrupted',
                'metric' => 'Lead form · 36h without signal',
                'scope' => '3 campaigns affected',
                'action' => 'Review',
                'tab' => 'measurement',
                'finding_id' => 'gads-f-measurement-gap',
            ],
            [
                'id' => 'att-intent',
                'severity' => 'High',
                'title' => 'Search intent drift',
                'metric' => '₺4,820 spend requires review',
                'scope' => '17 query clusters · 3 campaigns',
                'action' => 'Inspect',
                'tab' => 'search_demand',
                'finding_id' => 'gads-f-intent-drift',
            ],
            [
                'id' => 'att-landing',
                'severity' => 'Medium',
                'title' => 'Landing page issue',
                'metric' => '38% of paid clicks affected',
                'scope' => '/implant/ · mobile performance',
                'action' => 'Open Website',
                'tab' => 'landing_pages',
                'finding_id' => 'gads-f-landing-mobile',
            ],
            [
                'id' => 'att-budget',
                'severity' => 'Medium',
                'title' => 'Budget imbalance',
                'metric' => '1 constrained · 1 under pacing',
                'scope' => 'Breast Lift · Mommy Makeover',
                'action' => 'Inspect',
                'tab' => 'campaigns',
                'finding_id' => 'gads-f-budget-pace',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function performanceTrend(array $f): array
    {
        return [
            'labels' => DemoCatalog::trendSeries('gads_spend_leads', 14, 1, 1)['labels'],
            'spend' => DemoCatalog::trendSeries('gads_spend_series', 14, 2200, 4200)['values'],
            'leads' => DemoCatalog::trendSeries('gads_leads_series', 14, 4, 12)['values'],
            'compare_label' => 'vs previous '.$f['label'],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public static function campaigns(array $f): array
    {
        $sf = $f['spend_factor'] ?? 1.0;
        $rf = $f['results_factor'] ?? 1.0;

        return [
            [
                'id' => 'camp-pb-uk',
                'name' => 'Post Bariatric — UK Search',
                'type' => 'Search',
                'status' => 'ENABLED',
                'offering' => 'Post Bariatric',
                'market' => 'United Kingdom',
                'language' => 'English',
                'goal' => 'Qualified treatment enquiry',
                'primary_conversion' => 'Lead form',
                'funnel' => 'Decision / High intent',
                'search_strategy' => 'Turkey/Istanbul intent required',
                'price_intent' => 'Separate campaign',
                'competitor_intent' => 'Excluded',
                'brand_intent' => 'Separate campaign',
                'budget' => (int) round(22000 * $sf),
                'spend' => (int) round(18240 * $sf),
                'leads' => (int) round(41 * $rf),
                'cpa' => (int) round(445 * ($f['efficiency_factor'] ?? 1)),
                'pacing' => 'Ahead',
                'impr_share' => 54,
                'lost_is_budget' => 18,
                'lost_is_rank' => 12,
                'attention' => ['Search intent', 'Landing page'],
                'attention_primary' => 'Intent drift',
            ],
            [
                'id' => 'camp-mm-uk',
                'name' => 'Mommy Makeover — UK Search',
                'type' => 'Search',
                'status' => 'ENABLED',
                'offering' => 'Mommy Makeover',
                'market' => 'United Kingdom',
                'language' => 'English',
                'goal' => 'Qualified treatment enquiry',
                'primary_conversion' => 'Lead form',
                'funnel' => 'Decision / High intent',
                'search_strategy' => 'Turkey/Istanbul intent required',
                'price_intent' => 'Separate campaign',
                'competitor_intent' => 'Excluded',
                'brand_intent' => 'Separate campaign',
                'budget' => (int) round(20000 * $sf),
                'spend' => (int) round(14200 * $sf),
                'leads' => (int) round(33 * $rf),
                'cpa' => (int) round(430 * ($f['efficiency_factor'] ?? 1)),
                'pacing' => 'Behind',
                'impr_share' => 48,
                'lost_is_budget' => 6,
                'lost_is_rank' => 16,
                'attention' => ['Budget'],
                'attention_primary' => 'Under pacing',
            ],
            [
                'id' => 'camp-bl-uk',
                'name' => 'Breast Lift — UK Search',
                'type' => 'Search',
                'status' => 'ENABLED',
                'offering' => 'Breast Lift',
                'market' => 'United Kingdom',
                'language' => 'English',
                'goal' => 'Qualified treatment enquiry',
                'primary_conversion' => 'Lead form',
                'funnel' => 'Decision / High intent',
                'search_strategy' => 'Turkey/Istanbul intent required',
                'price_intent' => 'Separate campaign',
                'competitor_intent' => 'Excluded',
                'brand_intent' => 'Separate campaign',
                'budget' => (int) round(12000 * $sf),
                'spend' => (int) round(10880 * $sf),
                'leads' => (int) round(28 * $rf),
                'cpa' => (int) round(389 * ($f['efficiency_factor'] ?? 1)),
                'pacing' => 'Constrained',
                'impr_share' => 41,
                'lost_is_budget' => 28,
                'lost_is_rank' => 9,
                'attention' => ['Budget', 'Search intent'],
                'attention_primary' => 'Budget constrained',
            ],
            [
                'id' => 'camp-brand-uk',
                'name' => 'Brand — UK',
                'type' => 'Search',
                'status' => 'ENABLED',
                'offering' => 'Brand',
                'market' => 'United Kingdom',
                'language' => 'English',
                'goal' => 'Qualified treatment enquiry',
                'primary_conversion' => 'Lead form',
                'funnel' => 'Brand',
                'search_strategy' => 'Brand intent only',
                'price_intent' => 'N/A',
                'competitor_intent' => 'Excluded',
                'brand_intent' => 'This campaign',
                'budget' => (int) round(6000 * $sf),
                'spend' => (int) round(5000 * $sf),
                'leads' => (int) round(12 * $rf),
                'cpa' => (int) round(417 * ($f['efficiency_factor'] ?? 1)),
                'pacing' => 'On pace',
                'impr_share' => 82,
                'lost_is_budget' => 3,
                'lost_is_rank' => 4,
                'attention' => [],
                'attention_primary' => null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<array{offering: string, spend: int}>
     */
    public static function spendByOffering(array $campaigns): array
    {
        $map = [];
        foreach ($campaigns as $c) {
            $key = $c['offering'];
            $map[$key] = ($map[$key] ?? 0) + (int) $c['spend'];
        }
        $rows = [];
        foreach ($map as $offering => $spend) {
            $rows[] = ['offering' => $offering, 'spend' => $spend];
        }
        usort($rows, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function search(array $f): array
    {
        $sf = $f['spend_factor'] ?? 1.0;

        $terms = [
            ['term' => 'post bariatric dental turkey', 'campaign' => 'Post Bariatric — UK Search', 'ad_group' => 'PB Core', 'spend' => (int) round(1840 * $sf), 'clicks' => 120, 'leads' => 6, 'intent' => 'Service + location', 'fit' => 'Aligned', 'decision' => 'None'],
            ['term' => 'post bariatric surgery turkey package', 'campaign' => 'Post Bariatric — UK Search', 'ad_group' => 'PB Core', 'spend' => (int) round(1620 * $sf), 'clicks' => 98, 'leads' => 5, 'intent' => 'High intent', 'fit' => 'Aligned', 'decision' => 'None'],
            ['term' => 'breast lift cost uk', 'campaign' => 'Breast Lift — UK Search', 'ad_group' => 'BL Core', 'spend' => (int) round(1420 * $sf), 'clicks' => 160, 'leads' => 1, 'intent' => 'Price', 'fit' => 'Misaligned', 'decision' => 'Negative candidate'],
            ['term' => 'breast lift price london', 'campaign' => 'Breast Lift — UK Search', 'ad_group' => 'BL Core', 'spend' => (int) round(980 * $sf), 'clicks' => 110, 'leads' => 0, 'intent' => 'Price', 'fit' => 'Misaligned', 'decision' => 'Negative candidate'],
            ['term' => 'breast lift uk', 'campaign' => 'Breast Lift — UK Search', 'ad_group' => 'BL Core', 'spend' => (int) round(860 * $sf), 'clicks' => 95, 'leads' => 1, 'intent' => 'Core service', 'fit' => 'Review', 'decision' => 'Strategy review'],
            ['term' => 'mommy makeover turkey', 'campaign' => 'Mommy Makeover — UK Search', 'ad_group' => 'MM Core', 'spend' => (int) round(2100 * $sf), 'clicks' => 140, 'leads' => 8, 'intent' => 'Service + location', 'fit' => 'Aligned', 'decision' => 'None'],
            ['term' => 'mommy makeover cost uk', 'campaign' => 'Mommy Makeover — UK Search', 'ad_group' => 'MM Core', 'spend' => (int) round(1180 * $sf), 'clicks' => 130, 'leads' => 1, 'intent' => 'Price', 'fit' => 'Review', 'decision' => 'Keyword candidate'],
            ['term' => 'implant recovery turkey', 'campaign' => 'Post Bariatric — UK Search', 'ad_group' => 'PB Research', 'spend' => (int) round(740 * $sf), 'clicks' => 88, 'leads' => 2, 'intent' => 'Research', 'fit' => 'Review', 'decision' => 'Content opportunity'],
            ['term' => 'dental nurse jobs ankara', 'campaign' => 'Brand — UK', 'ad_group' => 'Brand Broad', 'spend' => (int) round(420 * $sf), 'clicks' => 210, 'leads' => 0, 'intent' => 'Jobs / career', 'fit' => 'Misaligned', 'decision' => 'Negative candidate'],
            ['term' => 'atlas dental ankara', 'campaign' => 'Brand — UK', 'ad_group' => 'Brand Exact', 'spend' => (int) round(380 * $sf), 'clicks' => 64, 'leads' => 9, 'intent' => 'Brand', 'fit' => 'Aligned', 'decision' => 'None'],
            ['term' => 'best dental clinic turkey reviews', 'campaign' => 'Post Bariatric — UK Search', 'ad_group' => 'PB Research', 'spend' => (int) round(560 * $sf), 'clicks' => 72, 'leads' => 1, 'intent' => 'Research', 'fit' => 'Review', 'decision' => 'Monitor'],
            ['term' => 'competitor smile clinic turkey', 'campaign' => 'Post Bariatric — UK Search', 'ad_group' => 'PB Broad', 'spend' => (int) round(510 * $sf), 'clicks' => 40, 'leads' => 0, 'intent' => 'Competitor', 'fit' => 'Misaligned', 'decision' => 'Negative candidate'],
        ];

        $clusters = [
            [
                'id' => 'cluster-bl-price-uk',
                'type' => 'Negative candidate',
                'title' => 'breast lift cost uk',
                'campaign' => 'Breast Lift — UK Search',
                'spend' => (int) round(2400 * $sf),
                'terms' => 13,
                'why' => 'Campaign requires Turkey/Istanbul intent; UK price queries lack destination intent.',
                'terms_list' => ['breast lift cost uk', 'breast lift price london', 'breast lift uk price'],
            ],
            [
                'id' => 'cluster-jobs',
                'type' => 'Negative candidate',
                'title' => 'dental jobs / career',
                'campaign' => 'Brand — UK',
                'spend' => (int) round(420 * $sf),
                'terms' => 4,
                'why' => 'Jobs/career queries are irrelevant to treatment acquisition.',
                'terms_list' => ['dental nurse jobs ankara', 'dentist vacancy turkey'],
            ],
            [
                'id' => 'cluster-mm-price',
                'type' => 'Keyword candidate',
                'title' => 'mommy makeover cost',
                'campaign' => 'Mommy Makeover — UK Search',
                'spend' => (int) round(1180 * $sf),
                'terms' => 6,
                'why' => 'Price intent may deserve a dedicated price campaign rather than negatives.',
                'terms_list' => ['mommy makeover cost uk', 'mommy makeover price turkey'],
            ],
            [
                'id' => 'cluster-recovery',
                'type' => 'Content opportunity',
                'title' => 'implant recovery turkey',
                'campaign' => 'Post Bariatric — UK Search',
                'spend' => (int) round(740 * $sf),
                'terms' => 5,
                'why' => 'Paid demand observed; Website content coverage weak; organic visibility low.',
                'terms_list' => ['implant recovery turkey', 'implant healing time turkey'],
            ],
        ];

        return [
            'subtitle' => 'What people searched, what intent we paid for, and which demand deserves operator action.',
            'terms_observed' => 1840,
            'aligned_high_intent_pct' => 62,
            'review_spend' => (int) round(4820 * $sf),
            'inbox_count' => 17,
            'intent_distribution' => [
                ['label' => 'High intent', 'pct' => 62],
                ['label' => 'Price', 'pct' => 14],
                ['label' => 'Research', 'pct' => 16],
                ['label' => 'Irrelevant', 'pct' => 8],
            ],
            'intent_drift' => [
                ['label' => 'High-intent share', 'from' => 76, 'to' => 62],
                ['label' => 'Research', 'from' => 10, 'to' => 16],
                ['label' => 'Irrelevant', 'from' => 3, 'to' => 8],
            ],
            'reviewable_spend' => [
                ['label' => 'Strategy mismatch', 'amount' => (int) round(4820 * $sf)],
                ['label' => 'Irrelevant query evidence', 'amount' => (int) round(2140 * $sf)],
                ['label' => 'Landing-page issue exposure', 'amount' => (int) round(1740 * $sf)],
                ['label' => 'Measurement uncertainty', 'amount' => (int) round(3600 * $sf)],
            ],
            'inbox_summary' => [
                'negative' => 17,
                'keyword' => 9,
                'content' => 4,
                'strategy' => 6,
            ],
            'terms' => $terms,
            'clusters' => $clusters,
            'keywords' => [
                ['keyword' => 'mommy makeover turkey', 'match' => 'Phrase', 'spend' => (int) round(4200 * $sf), 'leads' => 11, 'aligned' => 113, 'review' => 21, 'misaligned' => 8, 'observed' => 142],
                ['keyword' => 'post bariatric turkey', 'match' => 'Phrase', 'spend' => (int) round(5100 * $sf), 'leads' => 14, 'aligned' => 98, 'review' => 12, 'misaligned' => 5, 'observed' => 115],
                ['keyword' => 'breast lift turkey', 'match' => 'Phrase', 'spend' => (int) round(3800 * $sf), 'leads' => 10, 'aligned' => 70, 'review' => 28, 'misaligned' => 14, 'observed' => 112],
                ['keyword' => 'atlas dental', 'match' => 'Exact', 'spend' => (int) round(900 * $sf), 'leads' => 9, 'aligned' => 40, 'review' => 2, 'misaligned' => 0, 'observed' => 42],
            ],
            'intent_provenance' => 'Derived',
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function ads(array $f): array
    {
        return [
            'subtitle' => 'What message the campaign presents and whether it aligns with demand, Brand context and landing-page experience.',
            'rows' => [
                [
                    'id' => 'ad-pb-rsa',
                    'name' => 'PB RSA — Turkey package',
                    'campaign' => 'Post Bariatric — UK Search',
                    'ad_group' => 'PB Core',
                    'state' => 'ENABLED',
                    'final_url' => '/post-bariatric/',
                    'asset_coverage' => '4 / 6',
                    'policy' => 'Approved',
                    'theme' => 'Package · Expertise',
                    'landing_match' => 'Partial',
                    'intent_match' => 'Partial intent match',
                    'intent_why' => 'Ad under-emphasizes Turkey/location value for Turkey-intent queries.',
                    'brand_note' => 'Aligned with specialist positioning.',
                    'google_strength' => 'Good',
                    'headlines' => ['Post-Bariatric Dental Care', 'Atlas Dental Specialists', 'Treatment Plans in Turkey'],
                ],
                [
                    'id' => 'ad-bl-rsa',
                    'name' => 'BL RSA — Premium care',
                    'campaign' => 'Breast Lift — UK Search',
                    'ad_group' => 'BL Core',
                    'state' => 'ENABLED',
                    'final_url' => '/smile-design/',
                    'asset_coverage' => '3 / 6',
                    'policy' => 'Limited',
                    'theme' => 'Trust · Results',
                    'landing_match' => 'Weak',
                    'intent_match' => 'Partial intent match',
                    'intent_why' => '“Premium Dental Care” does not clearly communicate Turkey treatment proposition.',
                    'brand_note' => 'Review candidate — avoid cheapest framing.',
                    'google_strength' => 'Average',
                    'headlines' => ['Premium Dental Care', 'Book a Consultation', 'Experienced Specialists'],
                ],
                [
                    'id' => 'ad-brand',
                    'name' => 'Brand RSA',
                    'campaign' => 'Brand — UK',
                    'ad_group' => 'Brand Exact',
                    'state' => 'ENABLED',
                    'final_url' => '/',
                    'asset_coverage' => '5 / 6',
                    'policy' => 'Approved',
                    'theme' => 'Brand · Trust',
                    'landing_match' => 'Strong',
                    'intent_match' => 'Strong',
                    'intent_why' => 'Brand queries map cleanly to Brand homepage.',
                    'brand_note' => 'Aligned.',
                    'google_strength' => 'Excellent',
                    'headlines' => ['Atlas Dental Ankara', 'Book Your Visit', 'Trusted Clinic'],
                ],
            ],
            'policy_summary' => '2 ads limited by policy · 1 asset disapproved',
            'asset_groups' => [
                ['group' => 'Sitelinks', 'state' => 'Present'],
                ['group' => 'Callouts', 'state' => 'Present'],
                ['group' => 'Structured snippets', 'state' => 'Partial'],
                ['group' => 'Images', 'state' => 'Present'],
                ['group' => 'Call asset', 'state' => 'Present'],
                ['group' => 'Location asset', 'state' => 'Missing'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function landingPages(array $f): array
    {
        $sf = $f['spend_factor'] ?? 1.0;

        return [
            'subtitle' => 'Where paid traffic lands and whether those pages support campaign intent, technical quality and measurement.',
            'active' => 8,
            'need_review' => 3,
            'exposure_attention' => (int) round(24100 * $sf),
            'rows' => [
                [
                    'id' => 'lp-implant',
                    'url' => '/implant/',
                    'title' => 'Dental Implants in Ankara',
                    'spend' => (int) round(18400 * $sf),
                    'clicks' => 2140,
                    'leads' => 47,
                    'campaigns' => ['Post Bariatric — UK Search'],
                    'technical' => 'Good',
                    'mobile' => 'Needs attention',
                    'measurement' => 'Good',
                    'message' => 'Partial',
                    'language' => 'English',
                    'attention' => 'Mobile LCP',
                    'website_finding' => 'wf-mobile-lcp-implant',
                    'query_themes' => ['implant turkey package', 'post bariatric dental turkey'],
                    'ad_themes' => ['Package', 'Expertise'],
                    'message_reason' => 'Ads promise all-inclusive package; page leads with general Implant information.',
                ],
                [
                    'id' => 'lp-pb',
                    'url' => '/post-bariatric/',
                    'title' => 'Post-Bariatric Dentistry',
                    'spend' => (int) round(9800 * $sf),
                    'clicks' => 920,
                    'leads' => 28,
                    'campaigns' => ['Post Bariatric — UK Search'],
                    'technical' => 'Good',
                    'mobile' => 'Good',
                    'measurement' => 'Good',
                    'message' => 'Strong',
                    'language' => 'English',
                    'attention' => null,
                    'website_finding' => null,
                    'query_themes' => ['post bariatric surgery turkey'],
                    'ad_themes' => ['Package', 'Expertise'],
                    'message_reason' => 'Headline and offering align with paid intent.',
                ],
                [
                    'id' => 'lp-smile',
                    'url' => '/smile-design/',
                    'title' => 'Smile Design',
                    'spend' => (int) round(4200 * $sf),
                    'clicks' => 510,
                    'leads' => 8,
                    'campaigns' => ['Breast Lift — UK Search'],
                    'technical' => 'Good',
                    'mobile' => 'Good',
                    'measurement' => 'Needs mapping',
                    'message' => 'Weak',
                    'language' => 'Turkish',
                    'attention' => 'Language mismatch',
                    'website_finding' => null,
                    'query_themes' => ['breast lift turkey'],
                    'ad_themes' => ['Trust'],
                    'message_reason' => 'UK English campaign lands on Turkish-language page.',
                ],
                [
                    'id' => 'lp-home',
                    'url' => '/',
                    'title' => 'Atlas Dental Homepage',
                    'spend' => (int) round(3900 * $sf),
                    'clicks' => 640,
                    'leads' => 12,
                    'campaigns' => ['Brand — UK'],
                    'technical' => 'Good',
                    'mobile' => 'Good',
                    'measurement' => 'Good',
                    'message' => 'Strong',
                    'language' => 'English',
                    'attention' => null,
                    'website_finding' => null,
                    'query_themes' => ['atlas dental'],
                    'ad_themes' => ['Brand'],
                    'message_reason' => 'Brand destination matches Brand queries.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function measurement(): array
    {
        return [
            'subtitle' => 'Whether Google Ads performance is connected to reliable business outcomes.',
            'glance' => [
                'primary_goals' => 2,
                'healthy' => '1 / 2',
                'needs_mapping' => 2,
                'findings' => 3,
            ],
            'matrix' => [
                ['action' => 'Lead form', 'source' => 'Google Ads conversion', 'role' => 'Primary', 'state' => 'Healthy'],
                ['action' => 'WhatsApp', 'source' => 'GA4 import', 'role' => 'Secondary', 'state' => 'Needs mapping'],
                ['action' => 'Phone', 'source' => 'Google Ads', 'role' => 'Primary', 'state' => 'No recent signal'],
                ['action' => 'Appointment', 'source' => 'GA4', 'role' => '—', 'state' => 'Not configured'],
            ],
            'debt' => [
                ['label' => 'Unmapped business actions', 'count' => 2],
                ['label' => 'Primary signal needs review', 'count' => 1],
                ['label' => 'Possible duplicate signal', 'count' => 1],
            ],
            'duplicate_risk' => [
                'title' => 'Possible duplicate lead measurement',
                'detail' => 'Google Ads generate_lead and GA4 lead_submit may represent the same user action. Review mapping.',
            ],
            'interruption' => [
                'title' => 'Measurement requires investigation',
                'detail' => 'Lead form signal has not been observed for 36 hours while paid traffic continued.',
            ],
            'trust' => 'Primary lead form is healthy for period totals; phone primary signal needs investigation.',
            'ga4_label' => 'GA4 · measured Website behavior',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function operations(): array
    {
        return [
            'subtitle' => 'Paid acquisition findings, decisions, work and observed outcomes.',
            'findings' => [
                ['id' => 'gads-f-intent-drift', 'severity' => 'high', 'category' => 'Search demand', 'title' => 'Search intent has materially drifted from configured campaign strategy', 'status' => 'open'],
                ['id' => 'gads-f-measurement-gap', 'severity' => 'critical', 'category' => 'Measurement', 'title' => 'Primary lead measurement has not produced usable signal for 36 hours while paid traffic continues', 'status' => 'open'],
                ['id' => 'gads-f-landing-mobile', 'severity' => 'high', 'category' => 'Landing pages', 'title' => 'High-spend landing page has a critical Website performance Finding', 'status' => 'open'],
                ['id' => 'gads-f-budget-pace', 'severity' => 'medium', 'category' => 'Budget', 'title' => 'Campaign is significantly ahead of configured planned budget pace', 'status' => 'open'],
                ['id' => 'gads-f-language', 'severity' => 'medium', 'category' => 'Cross-asset', 'title' => 'Website and paid campaign language are inconsistent', 'status' => 'open'],
            ],
            'recommendations' => [
                ['id' => 'gads-r-negatives', 'title' => 'Review query clusters outside configured Turkey/Istanbul acquisition strategy', 'finding_id' => 'gads-f-intent-drift', 'status' => 'accepted'],
                ['id' => 'gads-r-whatsapp', 'title' => 'Review GA4/Google Ads conversion mapping for WhatsApp', 'finding_id' => 'gads-f-measurement-gap', 'status' => 'pending'],
                ['id' => 'gads-r-mobile', 'title' => 'Prioritize Website performance remediation on /implant/', 'finding_id' => 'gads-f-landing-mobile', 'status' => 'accepted'],
            ],
            'tasks' => [
                ['id' => 'gads-t-neg', 'title' => 'Review negative candidates', 'status' => 'completed', 'owner' => 'Media buyer', 'due' => '2 Aug'],
                ['id' => 'gads-t-map', 'title' => 'Configure internal measurement mapping', 'status' => 'completed', 'owner' => 'Ops', 'due' => '5 Aug'],
                ['id' => 'gads-t-lcp', 'title' => 'Website developer optimization — /implant/ mobile LCP', 'status' => 'completed', 'owner' => 'Website developer', 'due' => '8 Aug'],
                ['id' => 'gads-t-lang', 'title' => 'Align Breast Lift landing language with UK campaigns', 'status' => 'open', 'owner' => 'Content', 'due' => 'Next week'],
            ],
            'outcomes' => [
                ['task' => 'Review negative candidates', 'state' => 'Improvement observed', 'note' => 'Review-required spend share decreased from 18% to 7% in the later period.'],
                ['task' => 'Configure internal measurement mapping', 'state' => 'Improvement observed', 'note' => 'WhatsApp signal available in later evaluation — mapping reviewed internally.'],
                ['task' => 'Website developer optimization — /implant/ mobile LCP', 'state' => 'Improvement observed', 'note' => 'Later Website diagnosis no longer observed the performance Finding; comparable Ads data available.'],
                ['task' => 'Phone measurement investigation', 'state' => 'Insufficient evidence', 'note' => 'Later window still lacks a usable phone signal.'],
            ],
            'decision_history' => [
                ['when' => '2 Aug', 'event' => 'Recommendation accepted · Review negative candidates'],
                ['when' => '2 Aug', 'event' => 'Task completed · Review negative candidates'],
                ['when' => '8 Aug', 'event' => 'Later observation · review-required spend share 18% → 7%'],
                ['when' => '8 Aug', 'event' => 'Outcome recorded · Improvement observed'],
            ],
            'finding_detail' => [
                'gads-f-intent-drift' => [
                    'what' => 'High-intent share fell 76% → 62% while research/irrelevant shares rose on Turkey-intent Search campaigns.',
                    'why' => 'Spend may be buying demand outside configured Turkey/Istanbul strategy.',
                    'scope' => 'Post Bariatric · Breast Lift · Mommy Makeover',
                    'evidence' => 'Google Ads search terms · Derived intent · Campaign Context',
                    'next' => 'Review Decision Inbox clusters; accept internal negative candidates where appropriate (no Google write).',
                ],
                'gads-f-measurement-gap' => [
                    'what' => 'Lead form primary signal silent for 36h while clicks continued.',
                    'why' => 'Performance interpretation is limited until the signal is trustworthy.',
                    'scope' => '3 Search campaigns',
                    'evidence' => 'Google Ads conversions · Demo provider fixture',
                    'next' => 'Investigate measurement mapping before judging CPA changes.',
                ],
                'gads-f-landing-mobile' => [
                    'what' => '/implant/ receives ₺18.4K paid exposure with a Website mobile-performance Finding.',
                    'why' => 'Paid traffic amplifies Website experience risk.',
                    'scope' => 'Post Bariatric — UK Search',
                    'evidence' => 'Google Ads landing URLs · Website Finding',
                    'next' => 'Open Website Finding; prioritize remediation Task.',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function opportunities(): array
    {
        return [
            ['priority' => 'High', 'title' => 'Search demand', 'metric' => '9 keyword candidates', 'cta' => 'Review query clusters', 'tab' => 'search_demand'],
            ['priority' => 'High', 'title' => 'Landing pages', 'metric' => '3 pages need review · ₺24.1K exposure', 'cta' => 'Inspect landing pages', 'tab' => 'landing_pages'],
            ['priority' => 'Explore', 'title' => 'Organic overlap', 'metric' => '6 query clusters worth reviewing', 'cta' => 'Open Search & demand', 'tab' => 'search_demand'],
            ['priority' => 'Medium', 'title' => 'Budget allocation', 'metric' => '2 campaigns · redistribution review', 'cta' => 'Open campaigns', 'tab' => 'campaigns'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentOutcomes(): array
    {
        return [
            ['title' => 'Search intent review', 'state' => 'Improvement observed'],
            ['title' => 'Landing page mobile issue', 'state' => 'Awaiting follow-up'],
            ['title' => 'Phone measurement', 'state' => 'Insufficient evidence'],
        ];
    }
}
