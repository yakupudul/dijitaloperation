<?php

namespace App\Support\Demo;

use Carbon\Carbon;

/**
 * Deterministic Demo Mode fixtures for the Meta Ads Paid Social Operating Workspace.
 * No live Meta API. No runtime randomness — crc32/hash only.
 *
 * Daily account/campaign series cover 90 days ending {@see DemoPeriod::ANCHOR_DATE}
 * so custom ranges aggregate honestly from day buckets.
 */
final class MetaAdsWorkspaceFixtures
{
    private const string CURRENCY = 'TRY';

    private const int PLANNED_BUDGET = 100000;

    private const int PERIOD_ELAPSED_PCT = 61;

    /**
     * last_28 baseline spend / result targets (campaign spend sums to account spend).
     *
     * @var array<string, array{
     *     name: string,
     *     status: string,
     *     objective_family: string,
     *     optimization: string,
     *     destination: string,
     *     result_label: string,
     *     offering: string,
     *     market: string,
     *     language: string,
     *     goal: string,
     *     attention: ?string,
     *     story: ?string,
     *     spend: int,
     *     results: int,
     *     impressions: int,
     *     reach: int,
     *     link_clicks: int,
     *     frequency: float,
     *     ctr: float
     * }>
     */
    private const array CAMPAIGN_BASELINES = [
        'camp-pb-eu' => [
            'name' => 'Post Bariatric — Diaspora Lead',
            'status' => 'ACTIVE',
            'objective_family' => 'Leads',
            'optimization' => 'Leads',
            'destination' => 'Instant Form',
            'result_label' => 'Leads',
            'offering' => 'Post Bariatric',
            'market' => 'Germany',
            'language' => 'Turkish',
            'goal' => 'Acquisition',
            'attention' => 'high',
            'story' => null,
            'spend' => 24500,
            'results' => 68,
            'impressions' => 412000,
            'reach' => 186000,
            'link_clicks' => 6840,
            'frequency' => 2.2,
            'ctr' => 1.66,
        ],
        'camp-mm-eu' => [
            'name' => 'Mommy Makeover — Diaspora Lead',
            'status' => 'ACTIVE',
            'objective_family' => 'Leads',
            'optimization' => 'Leads',
            'destination' => 'Instant Form',
            'result_label' => 'Leads',
            'offering' => 'Mommy Makeover',
            'market' => 'Germany',
            'language' => 'Turkish',
            'goal' => 'Acquisition',
            'attention' => null,
            'story' => null,
            'spend' => 16800,
            'results' => 38,
            'impressions' => 298000,
            'reach' => 142000,
            'link_clicks' => 5120,
            'frequency' => 2.1,
            'ctr' => 1.72,
        ],
        'camp-bl-web' => [
            'name' => 'Breast Lift — Website',
            'status' => 'ACTIVE',
            'objective_family' => 'Website',
            'optimization' => 'Website leads/conversions',
            'destination' => 'Website',
            'result_label' => 'Website leads',
            'offering' => 'Breast Lift',
            'market' => 'Germany',
            'language' => 'German',
            'goal' => 'Acquisition',
            'attention' => 'medium',
            'story' => 'language_mismatch',
            'spend' => 9800,
            'results' => 12,
            'impressions' => 186000,
            'reach' => 98000,
            'link_clicks' => 3420,
            'frequency' => 1.9,
            'ctr' => 1.84,
        ],
        'camp-msg-retarget' => [
            'name' => 'Retargeting — Messaging',
            'status' => 'ACTIVE',
            'objective_family' => 'Messaging',
            'optimization' => 'Messaging conversations',
            'destination' => 'Messaging',
            'result_label' => 'Messaging conversations',
            'offering' => 'Multi-offering',
            'market' => 'Germany',
            'language' => 'Turkish',
            'goal' => 'Retargeting',
            'attention' => null,
            'story' => null,
            'spend' => 14200,
            'results' => 43,
            'impressions' => 164000,
            'reach' => 72000,
            'link_clicks' => 2180,
            'frequency' => 2.3,
            'ctr' => 1.33,
        ],
        'camp-aware-ig' => [
            'name' => 'Awareness — Instagram Profile',
            'status' => 'ACTIVE',
            'objective_family' => 'Awareness',
            'optimization' => 'Profile visits',
            'destination' => 'Instagram Profile',
            'result_label' => 'Instagram profile visits',
            'offering' => 'Brand',
            'market' => 'Germany',
            'language' => 'Turkish',
            'goal' => 'Awareness',
            'attention' => null,
            'story' => null,
            'spend' => 12440,
            'results' => 7023,
            'impressions' => 890000,
            'reach' => 520000,
            'link_clicks' => 9100,
            'frequency' => 1.7,
            'ctr' => 1.02,
        ],
        'camp-retarget' => [
            'name' => 'Retargeting — Form',
            'status' => 'PAUSED',
            'objective_family' => 'Leads',
            'optimization' => 'Leads',
            'destination' => 'Instant Form',
            'result_label' => 'Leads',
            'offering' => 'Multi-offering',
            'market' => 'Germany',
            'language' => 'Turkish',
            'goal' => 'Retargeting',
            'attention' => 'medium',
            'story' => null,
            'spend' => 6500,
            'results' => 16,
            'impressions' => 92000,
            'reach' => 41000,
            'link_clicks' => 1540,
            'frequency' => 2.6,
            'ctr' => 1.67,
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

        $campaigns = self::campaignsForRange($rangeStart, $rangeEnd);
        $totals = self::accountTotals($campaigns);
        $compareTotals = self::accountTotals(self::campaignsForRange(
            $prev['start']->toDateString(),
            $prev['end']->toDateString(),
        ));

        $leadSpend = 0;
        $leads = 0;
        foreach ($campaigns as $campaign) {
            if ($campaign['result_label'] === 'Leads') {
                $leadSpend += (int) $campaign['spend'];
                $leads += (int) $campaign['results'];
            }
        }
        $costPrimary = $leads > 0 ? (int) round($leadSpend / $leads) : null;

        $pacing = self::pacing((int) $totals['spend'], $f);
        $resultMix = self::resultMix($campaigns, $costPrimary);
        $creatives = self::creatives($campaigns, $rangeStart, $rangeEnd);

        return [
            'period_label' => $f['label'],
            'period_days' => $bounds['days'],
            'period_start' => $rangeStart,
            'period_end' => $rangeEnd,
            'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'Demo Mode · product vision fixtures — no live Meta write or Graph API',
            'identity' => self::identity(),
            'freshness' => self::freshness(),
            'glance' => self::glance($totals, $resultMix, $costPrimary, $pacing, $compareTotals, $f),
            'result_mix' => $resultMix,
            'pacing' => $pacing,
            'needs_attention' => self::needsAttention($campaigns),
            'performance_trend' => self::performanceTrend($rangeStart, $rangeEnd, $f),
            'campaigns' => $campaigns,
            'creative_pulse' => self::creativePulse($creatives),
            'creatives' => $creatives,
            'audience' => self::audience($totals),
            'funnel' => self::funnel($campaigns, $totals),
            'measurement' => self::measurement($leads),
            'operations' => self::operations(),
            'opportunities' => self::opportunities(),
            'recent_outcomes' => self::recentOutcomes(),
            'narrative' => $f['narrative'] ?? null,
            'currency' => self::CURRENCY,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function campaignDetail(string $id, string $preset = 'last_28', ?string $start = null, ?string $end = null): ?array
    {
        $workspace = self::workspace($preset, $start, $end);
        $campaign = null;
        foreach ($workspace['campaigns'] as $row) {
            if ($row['id'] === $id) {
                $campaign = $row;
                break;
            }
        }

        if ($campaign === null) {
            return null;
        }

        $adSets = self::adSetsForCampaign($id, (int) $campaign['spend'], (int) $campaign['results'], (string) $campaign['result_label']);
        $creatives = array_values(array_filter(
            $workspace['creatives']['gallery'],
            static fn (array $c): bool => ($c['campaign_id'] ?? null) === $id,
        ));

        return array_merge($campaign, [
            'period_label' => $workspace['period_label'],
            'period_start' => $workspace['period_start'],
            'period_end' => $workspace['period_end'],
            'compare_label' => $workspace['compare_label'],
            'adsets' => $adSets,
            'creatives' => $creatives,
            'audience' => self::audienceForCampaign($id, (int) $campaign['spend']),
            'funnel' => [
                'destination' => $campaign['destination'],
                'optimization' => $campaign['optimization'],
                'result_label' => $campaign['result_label'],
                'results' => $campaign['results'],
                'cost_result' => $campaign['cost_result'],
            ],
            'kpis' => [
                ['label' => 'Spend', 'value' => (int) $campaign['spend'], 'format' => 'try'],
                ['label' => $campaign['result_label'], 'value' => (int) $campaign['results'], 'format' => 'int'],
                ['label' => 'Cost / Result', 'value' => $campaign['cost_result'], 'format' => 'try'],
                ['label' => 'Link CTR', 'value' => $campaign['ctr'], 'format' => 'pct'],
                ['label' => 'Frequency', 'value' => $campaign['frequency'], 'format' => 'float'],
            ],
            'demo_boundary' => $workspace['demo_boundary'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'eyebrow' => 'Meta Ads',
            'title' => 'Atlas Health — Europe',
            'brand' => 'Atlas Health Group',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Health Group',
            'website_asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
            'meta_asset_id' => DemoCatalog::META_ASSET_ID,
            'strategy_line' => 'Germany · Turkish · Acquisition',
            'status' => 'Connected · Data through Aug 12',
            'reporting_timezone' => DemoPeriod::TIMEZONE,
            'currency' => self::CURRENCY,
            'ad_account' => 'Atlas Health — Europe (act_demo_atlas_eu)',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function freshness(): array
    {
        return [
            ['source' => 'Meta Ads', 'age' => '2h', 'detail' => 'Last successful collection · Aug 12 22:40 Europe/Berlin'],
            ['source' => 'CRM (demo)', 'age' => '4h', 'detail' => 'Lead quality stages connected for Instant Form leads'],
            ['source' => 'Website', 'age' => '4h', 'detail' => 'Destination language / URL consistency Demo'],
            ['source' => 'Brand Context', 'age' => 'Current', 'detail' => 'Operator maintained'],
        ];
    }

    /**
     * @param  array{spend: int, impressions: int, reach: int, link_clicks: int, by_label: array<string, array{label: string, results: int, spend: int}>}  $totals
     * @param  list<array<string, mixed>>  $resultMix
     * @param  array<string, mixed>  $pacing
     * @param  array{spend: int, by_label: array<string, array{label: string, results: int, spend: int}>}  $compareTotals
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function glance(array $totals, array $resultMix, ?int $costPrimary, array $pacing, array $compareTotals, array $f): array
    {
        $spendDelta = self::pctDelta((int) $totals['spend'], (int) $compareTotals['spend']);
        $primary = $resultMix[0] ?? null;
        $secondary = array_slice($resultMix, 1);

        return [
            'spend' => [
                'value' => '₺'.number_format((int) $totals['spend']),
                'raw' => (int) $totals['spend'],
                'secondary' => self::formatDelta($spendDelta).' vs previous '.$f['label'],
                'tone' => 'neutral',
            ],
            'result_mix' => [
                'primary' => $primary,
                'secondary' => $secondary,
                'note' => 'Heterogeneous objectives — do not sum into one total',
            ],
            'cost_primary' => [
                'value' => $costPrimary !== null ? '₺'.number_format($costPrimary) : 'Cost / lead unavailable',
                'raw' => $costPrimary,
                'secondary' => $costPrimary !== null
                    ? 'Primary Instant Form leads · '.$f['label']
                    : 'No lead results in period',
                'tone' => $costPrimary !== null && $costPrimary > 450 ? 'warning' : 'neutral',
            ],
            'pacing' => [
                'value' => (string) $pacing['state'],
                'secondary' => $pacing['summary'],
                'tone' => $pacing['state'] === 'On plan' ? 'positive' : 'warning',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function pacing(int $spend, array $f): array
    {
        $planned = self::PLANNED_BUDGET;
        $elapsedPct = self::PERIOD_ELAPSED_PCT;
        $days = (int) ($f['days'] ?? 28);

        // last_28 baseline story: On plan · ~63% spent · 61% elapsed against 100000 planned.
        // Other windows scale the spend share deterministically from day ratio.
        if ($days === 28) {
            $spendPct = 63;
        } else {
            $spendPct = (int) round(63 * ($days / 28));
            $spendPct = max(8, min(120, $spendPct));
        }

        $expected = (int) round($planned * ($elapsedPct / 100));
        $state = abs($spendPct - $elapsedPct) <= 8 ? 'On plan' : ($spendPct > $elapsedPct ? 'Ahead of plan' : 'Behind plan');

        return [
            'source' => 'Agency planned budget · operator context',
            'monthly_budget' => $planned,
            'planned_for_period' => $planned,
            'elapsed_pct' => $elapsedPct,
            'expected_spend' => $expected,
            'actual_spend' => $spend,
            'spend_pct' => $spendPct,
            'remaining' => max(0, $planned - $spend),
            'state' => $state,
            'summary' => $spendPct.'% spent · '.$elapsedPct.'% period elapsed',
            'projected' => (int) round($planned * ($spendPct / max(1, $elapsedPct))),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<array<string, mixed>>
     */
    public static function needsAttention(array $campaigns): array
    {
        $pb = self::findCampaign($campaigns, 'camp-pb-eu');
        $bl = self::findCampaign($campaigns, 'camp-bl-web');

        return [
            [
                'id' => 'att-creative-fatigue',
                'severity' => 'High',
                'title' => 'Transformation V2 frequency rising',
                'metric' => 'Frequency 1.6 → 2.8 · CTR down · cost up',
                'scope' => ($pb['name'] ?? 'Post Bariatric — Diaspora Lead').' · Trust/Transform creatives',
                'action' => 'Inspect creatives',
                'tab' => 'creatives',
                'finding_id' => 'meta-f-fatigue',
            ],
            [
                'id' => 'att-lead-quality',
                'severity' => 'High',
                'title' => 'Lead quality gap on Instant Form',
                'metric' => '122 platform leads → 87 CRM accepted',
                'scope' => 'Post Bariatric · Mommy Makeover · Retargeting Form',
                'action' => 'Review measurement',
                'tab' => 'measurement',
                'finding_id' => 'meta-f-lead-quality',
            ],
            [
                'id' => 'att-destination-lang',
                'severity' => 'Medium',
                'title' => 'Destination language mismatch',
                'metric' => 'German campaign → English landing page',
                'scope' => $bl['name'] ?? 'Breast Lift — Website',
                'action' => 'Open Website',
                'tab' => 'funnel',
                'finding_id' => 'meta-f-destination-lang',
            ],
            [
                'id' => 'att-price-creative',
                'severity' => 'Medium',
                'title' => 'Price V6 cheap Meta CPL, weak qualified',
                'metric' => 'Low platform CPL · weak CRM accept rate',
                'scope' => 'Mommy Makeover · Instant Form',
                'action' => 'Inspect creative',
                'tab' => 'creatives',
                'finding_id' => 'meta-f-price-quality',
            ],
            [
                'id' => 'att-expert-history',
                'severity' => 'Low',
                'title' => 'Expert V1 insufficient history',
                'metric' => 'New creative · not enough delivery for a call',
                'scope' => 'Post Bariatric — Diaspora Lead',
                'action' => 'Monitor',
                'tab' => 'creatives',
                'finding_id' => 'meta-f-insufficient-evidence',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function performanceTrend(string $rangeStart, string $rangeEnd, array $f): array
    {
        $days = self::daysInRange($rangeStart, $rangeEnd);
        $labels = [];
        $spend = [];
        $leads = [];
        $messaging = [];

        // Cap chart points for readability while remaining deterministic.
        $step = max(1, (int) ceil(count($days) / 14));
        foreach (array_values($days) as $index => $date) {
            if ($index % $step !== 0 && $index !== count($days) - 1) {
                continue;
            }
            $day = self::accountDay($date);
            $labels[] = Carbon::parse($date, DemoPeriod::TIMEZONE)->format('M j');
            $spend[] = $day['spend'];
            $leads[] = $day['leads'];
            $messaging[] = $day['messaging'];
        }

        return [
            'labels' => $labels,
            'spend' => $spend,
            'leads' => $leads,
            'messaging' => $messaging,
            'compare_label' => 'vs previous '.$f['label'],
            'note' => 'Daily Demo fixtures · Europe/Berlin · through '.DemoPeriod::ANCHOR_DATE,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function campaignsForRange(string $start, string $end): array
    {
        $rows = [];
        foreach (self::CAMPAIGN_BASELINES as $id => $base) {
            $agg = self::aggregateCampaign($id, $start, $end);
            $results = (int) $agg['results'];
            $spend = (int) $agg['spend'];
            $rows[] = [
                'id' => $id,
                'name' => $base['name'],
                'status' => $base['status'],
                'objective_family' => $base['objective_family'],
                'optimization' => $base['optimization'],
                'destination' => $base['destination'],
                'result_label' => $base['result_label'],
                'offering' => $base['offering'],
                'market' => $base['market'],
                'language' => $base['language'],
                'goal' => $base['goal'],
                'attention' => $base['attention'],
                'story' => $base['story'],
                'context' => [
                    'offering' => $base['offering'],
                    'market' => $base['market'],
                    'language' => $base['language'],
                    'goal' => $base['goal'],
                    'destination' => $base['destination'],
                    'optimization' => $base['optimization'],
                ],
                'spend' => $spend,
                'results' => $results,
                'cost_result' => $results > 0 ? (int) round($spend / $results) : null,
                'impressions' => (int) $agg['impressions'],
                'reach' => (int) $agg['reach'],
                'link_clicks' => (int) $agg['link_clicks'],
                'frequency' => round((float) $agg['frequency'], 2),
                'ctr' => round((float) $agg['ctr'], 2),
                'delivered' => $spend > 0 || $agg['impressions'] > 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<array<string, mixed>>
     */
    public static function resultMix(array $campaigns, ?int $costPrimary): array
    {
        $buckets = [];
        foreach ($campaigns as $campaign) {
            $label = (string) $campaign['result_label'];
            if (! isset($buckets[$label])) {
                $buckets[$label] = [
                    'label' => $label,
                    'results' => 0,
                    'spend' => 0,
                ];
            }
            $buckets[$label]['results'] += (int) $campaign['results'];
            $buckets[$label]['spend'] += (int) $campaign['spend'];
        }

        $order = ['Leads', 'Messaging conversations', 'Instagram profile visits', 'Website leads'];
        $mix = [];
        foreach ($order as $label) {
            if (! isset($buckets[$label])) {
                continue;
            }
            $row = $buckets[$label];
            $row['results_display'] = number_format($row['results']);
            $row['spend_display'] = '₺'.number_format($row['spend']);
            $row['cost_result'] = $row['results'] > 0 ? (int) round($row['spend'] / $row['results']) : null;
            $row['cost_result_display'] = $row['cost_result'] !== null ? '₺'.number_format($row['cost_result']) : null;
            if ($label === 'Leads' && $costPrimary !== null) {
                $row['cost_result'] = $costPrimary;
                $row['cost_result_display'] = '₺'.number_format($costPrimary);
                $row['role'] = 'primary';
            } else {
                $row['role'] = 'secondary';
            }
            $mix[] = $row;
            unset($buckets[$label]);
        }
        foreach ($buckets as $row) {
            $row['results_display'] = number_format($row['results']);
            $row['spend_display'] = '₺'.number_format($row['spend']);
            $row['cost_result'] = $row['results'] > 0 ? (int) round($row['spend'] / $row['results']) : null;
            $row['cost_result_display'] = $row['cost_result'] !== null ? '₺'.number_format($row['cost_result']) : null;
            $row['role'] = 'secondary';
            $mix[] = $row;
        }

        return $mix;
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return array{gallery: list<array<string, mixed>>, angles: list<array<string, mixed>>, coverage: array<string, mixed>, personas: list<array<string, mixed>>, tests: list<array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    public static function creatives(array $campaigns, string $start, string $end): array
    {
        $spendByCampaign = [];
        foreach ($campaigns as $campaign) {
            $spendByCampaign[$campaign['id']] = (int) $campaign['spend'];
        }

        $defs = [
            [
                'id' => 'cr-trust-v3',
                'name' => 'Trust V3',
                'angle' => 'Trust',
                'thumb_gradient' => 'trust',
                'campaign_id' => 'camp-pb-eu',
                'format' => 'Image',
                'status' => 'ACTIVE',
                'persona' => 'Diaspora women 35–54',
                'spend_share' => 0.38,
                'result_share' => 0.42,
                'result_label' => 'Leads',
                'frequency' => 1.9,
                'ctr' => 1.92,
                'note' => 'Stable delivery · good qualified rate in CRM demo',
                'signal' => 'stable_qualified',
            ],
            [
                'id' => 'cr-price-v6',
                'name' => 'Price V6',
                'angle' => 'Price',
                'thumb_gradient' => 'price',
                'campaign_id' => 'camp-mm-eu',
                'format' => 'Image',
                'status' => 'ACTIVE',
                'persona' => 'Price-sensitive diaspora',
                'spend_share' => 0.55,
                'result_share' => 0.62,
                'result_label' => 'Leads',
                'frequency' => 2.1,
                'ctr' => 2.24,
                'note' => 'Cheap Meta CPL · weak qualified accept rate',
                'signal' => 'cheap_weak_qualified',
            ],
            [
                'id' => 'cr-transform-v2',
                'name' => 'Transformation V2',
                'angle' => 'Transformation',
                'thumb_gradient' => 'transform',
                'campaign_id' => 'camp-pb-eu',
                'format' => 'Video',
                'status' => 'ACTIVE',
                'persona' => 'Diaspora women 35–54',
                'spend_share' => 0.44,
                'result_share' => 0.36,
                'result_label' => 'Leads',
                'frequency' => 2.8,
                'ctr' => 1.18,
                'note' => 'Fatigue candidate · frequency 1.6→2.8 · CTR down · cost up',
                'signal' => 'fatigue_candidate',
                'frequency_from' => 1.6,
                'frequency_to' => 2.8,
            ],
            [
                'id' => 'cr-expert-v1',
                'name' => 'Expert V1',
                'angle' => 'Expert',
                'thumb_gradient' => 'expert',
                'campaign_id' => 'camp-pb-eu',
                'format' => 'Image',
                'status' => 'ACTIVE',
                'persona' => 'Trust-seeking decision makers',
                'spend_share' => 0.12,
                'result_share' => 0.10,
                'result_label' => 'Leads',
                'frequency' => 1.2,
                'ctr' => 1.55,
                'note' => 'New creative · insufficient history for a call',
                'signal' => 'insufficient_history',
            ],
            [
                'id' => 'cr-trust-carousel',
                'name' => 'Trust Carousel A',
                'angle' => 'Trust',
                'thumb_gradient' => 'trust',
                'campaign_id' => 'camp-mm-eu',
                'format' => 'Carousel',
                'status' => 'ACTIVE',
                'persona' => 'Diaspora mothers',
                'spend_share' => 0.30,
                'result_share' => 0.28,
                'result_label' => 'Leads',
                'frequency' => 1.8,
                'ctr' => 1.71,
                'note' => 'Coverage creative · stable',
                'signal' => 'coverage',
            ],
            [
                'id' => 'cr-msg-soft',
                'name' => 'Messaging Soft Ask',
                'angle' => 'Conversation',
                'thumb_gradient' => 'trust',
                'campaign_id' => 'camp-msg-retarget',
                'format' => 'Image',
                'status' => 'ACTIVE',
                'persona' => 'Site visitors 14d',
                'spend_share' => 0.60,
                'result_share' => 0.58,
                'result_label' => 'Messaging conversations',
                'frequency' => 2.0,
                'ctr' => 1.40,
                'note' => 'Retargeting messaging · coherent destination',
                'signal' => 'coverage',
            ],
            [
                'id' => 'cr-ig-profile',
                'name' => 'Profile Visit Reel',
                'angle' => 'Awareness',
                'thumb_gradient' => 'transform',
                'campaign_id' => 'camp-aware-ig',
                'format' => 'Video',
                'status' => 'ACTIVE',
                'persona' => 'Broad DE Turkish interest',
                'spend_share' => 0.70,
                'result_share' => 0.72,
                'result_label' => 'Instagram profile visits',
                'frequency' => 1.6,
                'ctr' => 1.05,
                'note' => 'Awareness coverage · profile destination',
                'signal' => 'coverage',
            ],
            [
                'id' => 'cr-bl-web-de',
                'name' => 'Breast Lift DE Hook',
                'angle' => 'Offer',
                'thumb_gradient' => 'price',
                'campaign_id' => 'camp-bl-web',
                'format' => 'Image',
                'status' => 'ACTIVE',
                'persona' => 'DE interest · aesthetic',
                'spend_share' => 0.75,
                'result_share' => 0.70,
                'result_label' => 'Website leads',
                'frequency' => 1.9,
                'ctr' => 1.80,
                'note' => 'German ad copy · English landing page mismatch story',
                'signal' => 'destination_language',
            ],
        ];

        $gallery = [];
        foreach ($defs as $def) {
            $campaignSpend = $spendByCampaign[$def['campaign_id']] ?? 0;
            $spend = (int) round($campaignSpend * $def['spend_share']);
            $campaignResults = 0;
            foreach ($campaigns as $campaign) {
                if ($campaign['id'] === $def['campaign_id']) {
                    $campaignResults = (int) $campaign['results'];
                    break;
                }
            }
            $results = (int) round($campaignResults * $def['result_share']);
            $gallery[] = [
                'id' => $def['id'],
                'name' => $def['name'],
                'angle' => $def['angle'],
                'thumb_gradient' => $def['thumb_gradient'],
                'campaign_id' => $def['campaign_id'],
                'campaign_name' => self::CAMPAIGN_BASELINES[$def['campaign_id']]['name'] ?? $def['campaign_id'],
                'format' => $def['format'],
                'status' => $def['status'],
                'persona' => $def['persona'],
                'spend' => $spend,
                'spend_display' => '₺'.number_format($spend),
                'results' => $results,
                'results_display' => number_format($results),
                'result_label' => $def['result_label'],
                'cost_result' => $results > 0 ? (int) round($spend / $results) : null,
                'frequency' => $def['frequency'],
                'ctr' => $def['ctr'],
                'note' => $def['note'],
                'signal' => $def['signal'],
                'frequency_from' => $def['frequency_from'] ?? null,
                'frequency_to' => $def['frequency_to'] ?? null,
                'period_start' => $start,
                'period_end' => $end,
            ];
        }

        $angles = [
            ['angle' => 'Trust', 'creatives' => 2, 'note' => 'Strongest qualified story'],
            ['angle' => 'Price', 'creatives' => 2, 'note' => 'Platform CPL looks good; CRM weaker'],
            ['angle' => 'Transformation', 'creatives' => 1, 'note' => 'Fatigue candidate under watch'],
            ['angle' => 'Expert', 'creatives' => 1, 'note' => 'New · insufficient history'],
            ['angle' => 'Conversation', 'creatives' => 1, 'note' => 'Messaging retarget'],
            ['angle' => 'Awareness', 'creatives' => 1, 'note' => 'Profile visits'],
            ['angle' => 'Offer', 'creatives' => 1, 'note' => 'Website destination'],
        ];

        $coverage = [
            'angles_covered' => 7,
            'formats' => ['Image' => 5, 'Video' => 2, 'Carousel' => 1],
            'campaigns_with_creative' => 5,
            'gaps' => ['No Instant Form creative on Breast Lift Website', 'Expert angle still thin'],
        ];

        $personas = [
            ['persona' => 'Diaspora women 35–54', 'creatives' => 2, 'fit' => 'Aligned'],
            ['persona' => 'Price-sensitive diaspora', 'creatives' => 1, 'fit' => 'Watch quality'],
            ['persona' => 'Site visitors 14d', 'creatives' => 1, 'fit' => 'Aligned'],
            ['persona' => 'Broad DE Turkish interest', 'creatives' => 1, 'fit' => 'Awareness only'],
        ];

        $tests = [
            [
                'id' => 'test-trust-vs-transform',
                'title' => 'Trust V3 vs Transformation V2',
                'status' => 'In market',
                'note' => 'Transformation showing fatigue pattern; Trust remains stable.',
            ],
            [
                'id' => 'test-price-vs-trust-mm',
                'title' => 'Price V6 vs Trust Carousel A',
                'status' => 'In market',
                'note' => 'Price wins Meta CPL; Trust wins CRM accept rate.',
            ],
            [
                'id' => 'test-expert-v1',
                'title' => 'Expert V1 learning',
                'status' => 'Insufficient evidence',
                'note' => 'Not enough delivery history for a replacement call.',
            ],
        ];

        $variants = [
            ['family' => 'Trust', 'variants' => ['Trust V3', 'Trust Carousel A'], 'active' => 2],
            ['family' => 'Price', 'variants' => ['Price V6', 'Breast Lift DE Hook'], 'active' => 2],
            ['family' => 'Transformation', 'variants' => ['Transformation V2'], 'active' => 1],
            ['family' => 'Expert', 'variants' => ['Expert V1'], 'active' => 1],
        ];

        return [
            'gallery' => $gallery,
            'angles' => $angles,
            'coverage' => $coverage,
            'personas' => $personas,
            'tests' => $tests,
            'variants' => $variants,
        ];
    }

    /**
     * @param  array{gallery: list<array<string, mixed>>, angles: list<array<string, mixed>>, coverage: array<string, mixed>, personas: list<array<string, mixed>>, tests: list<array<string, mixed>>, variants: list<array<string, mixed>>}  $creatives
     * @return array<string, mixed>
     */
    public static function creativePulse(array $creatives): array
    {
        $fatigue = null;
        $stable = null;
        $weak = null;
        $new = null;
        foreach ($creatives['gallery'] as $row) {
            if ($row['signal'] === 'fatigue_candidate') {
                $fatigue = $row;
            }
            if ($row['signal'] === 'stable_qualified') {
                $stable = $row;
            }
            if ($row['signal'] === 'cheap_weak_qualified') {
                $weak = $row;
            }
            if ($row['signal'] === 'insufficient_history') {
                $new = $row;
            }
        }

        return [
            'subtitle' => 'Creative stories visible without fake fatigue/health scores.',
            'stable' => $stable,
            'fatigue_candidate' => $fatigue,
            'cheap_weak_qualified' => $weak,
            'insufficient_history' => $new,
            'counts' => [
                'gallery' => count($creatives['gallery']),
                'angles' => count($creatives['angles']),
                'tests' => count($creatives['tests']),
            ],
        ];
    }

    /**
     * @param  array{spend: int, impressions: int, reach: int, link_clicks: int}  $totals
     * @return array<string, mixed>
     */
    public static function audience(array $totals): array
    {
        $spend = max(1, (int) $totals['spend']);

        return [
            'subtitle' => 'Configured targeting vs observed delivery — bars are Demo fixtures, not live Graph breakdowns.',
            'configured' => [
                'locations' => ['Germany'],
                'languages' => ['Turkish', 'German'],
                'age' => '25–54',
                'gender' => 'All (skew female expected)',
                'platforms' => ['Facebook', 'Instagram'],
                'placements' => ['Advantage+ placements (Feed, Stories, Reels)'],
            ],
            'observed' => [
                'placement' => self::barRows([
                    ['label' => 'Instagram Feed', 'share' => 34],
                    ['label' => 'Facebook Feed', 'share' => 28],
                    ['label' => 'Instagram Stories', 'share' => 18],
                    ['label' => 'Reels', 'share' => 14],
                    ['label' => 'Audience Network', 'share' => 6],
                ], $spend),
                'age' => self::barRows([
                    ['label' => '18–24', 'share' => 8],
                    ['label' => '25–34', 'share' => 36],
                    ['label' => '35–44', 'share' => 32],
                    ['label' => '45–54', 'share' => 18],
                    ['label' => '55+', 'share' => 6],
                ], $spend),
                'country' => self::barRows([
                    ['label' => 'Germany', 'share' => 78],
                    ['label' => 'Netherlands', 'share' => 9],
                    ['label' => 'Austria', 'share' => 7],
                    ['label' => 'Other EU', 'share' => 6],
                ], $spend),
                'gender' => self::barRows([
                    ['label' => 'Female', 'share' => 71],
                    ['label' => 'Male', 'share' => 26],
                    ['label' => 'Unknown', 'share' => 3],
                ], $spend),
                'platform' => self::barRows([
                    ['label' => 'Instagram', 'share' => 58],
                    ['label' => 'Facebook', 'share' => 39],
                    ['label' => 'Audience Network', 'share' => 3],
                ], $spend),
            ],
            'gaps' => [
                'Configured Germany-only; observed 22% delivery outside Germany (NL/AT/other).',
                'Audience Network still taking a small share despite lead-quality focus.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @param  array{spend: int}  $totals
     * @return array<string, mixed>
     */
    public static function funnel(array $campaigns, array $totals): array
    {
        $destinations = [
            'Website' => ['campaigns' => [], 'spend' => 0, 'results' => 0, 'result_label' => 'Website leads'],
            'Instant Forms' => ['campaigns' => [], 'spend' => 0, 'results' => 0, 'result_label' => 'Leads'],
            'Messaging' => ['campaigns' => [], 'spend' => 0, 'results' => 0, 'result_label' => 'Messaging conversations'],
            'Instagram Profile' => ['campaigns' => [], 'spend' => 0, 'results' => 0, 'result_label' => 'Instagram profile visits'],
        ];

        foreach ($campaigns as $campaign) {
            $key = match ($campaign['destination']) {
                'Website' => 'Website',
                'Instant Form' => 'Instant Forms',
                'Messaging' => 'Messaging',
                'Instagram Profile' => 'Instagram Profile',
                default => 'Website',
            };
            $destinations[$key]['campaigns'][] = $campaign['name'];
            $destinations[$key]['spend'] += (int) $campaign['spend'];
            if ($campaign['result_label'] === $destinations[$key]['result_label']
                || ($key === 'Instant Forms' && $campaign['result_label'] === 'Leads')) {
                $destinations[$key]['results'] += (int) $campaign['results'];
            }
        }

        $rows = [];
        foreach ($destinations as $label => $row) {
            $rows[] = [
                'destination' => $label,
                'campaigns' => $row['campaigns'],
                'spend' => $row['spend'],
                'spend_display' => '₺'.number_format($row['spend']),
                'results' => $row['results'],
                'results_display' => number_format($row['results']),
                'result_label' => $row['result_label'],
                'share_pct' => (int) round(($row['spend'] / max(1, (int) $totals['spend'])) * 100),
            ];
        }

        return [
            'subtitle' => 'Where Meta traffic is sent — Website, Instant Forms, Messaging, Instagram Profile.',
            'destinations' => $rows,
            'stories' => [
                [
                    'id' => 'funnel-lang',
                    'title' => 'Breast Lift Website language mismatch',
                    'detail' => 'German-language campaign creative lands on an English LP — destination consistency Finding.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function measurement(int $platformLeads): array
    {
        $leads = max(1, $platformLeads);
        $scale = $leads / 122;
        $crmAccepted = (int) round(87 * $scale);
        $consultBooked = (int) round(31 * $scale);
        $qualified = (int) round(14 * $scale);
        $treated = (int) round(6 * $scale);

        return [
            'subtitle' => 'Platform results mapped to CRM demo outcomes — no fake composite scores.',
            'result_mapping' => [
                ['platform' => 'Instant Form lead', 'role' => 'Primary', 'state' => 'Mapped', 'trust' => 'Healthy'],
                ['platform' => 'Messaging conversation', 'role' => 'Secondary', 'state' => 'Mapped', 'trust' => 'Partial'],
                ['platform' => 'Instagram profile visit', 'role' => 'Awareness', 'state' => 'Observed', 'trust' => 'Platform-only'],
                ['platform' => 'Website lead / conversion', 'role' => 'Secondary', 'state' => 'Needs review', 'trust' => 'Debt'],
            ],
            'crm' => [
                'connected' => true,
                'label' => 'CRM demo connected',
                'detail' => 'Atlas Health lead stages available for Instant Form leads in Demo Mode.',
            ],
            'business_outcome_funnel' => [
                ['stage' => 'Platform leads', 'count' => $leads, 'display' => number_format($leads)],
                ['stage' => 'CRM accepted', 'count' => $crmAccepted, 'display' => number_format($crmAccepted)],
                ['stage' => 'Consult booked', 'count' => $consultBooked, 'display' => number_format($consultBooked)],
                ['stage' => 'Qualified', 'count' => $qualified, 'display' => number_format($qualified)],
                ['stage' => 'Treated', 'count' => $treated, 'display' => number_format($treated)],
            ],
            'lead_quality_gap' => [
                'title' => 'Lead quality gap',
                'platform_leads' => $leads,
                'crm_accepted' => $crmAccepted,
                'gap' => max(0, $leads - $crmAccepted),
                'note' => 'Price-led Instant Form creatives inflate platform leads relative to CRM accept.',
            ],
            'trust_states' => [
                ['area' => 'Primary Instant Form mapping', 'state' => 'Trusted'],
                ['area' => 'CRM stage progression', 'state' => 'Trusted (demo)'],
                ['area' => 'Website conversion import', 'state' => 'Needs review'],
                ['area' => 'Messaging → CRM', 'state' => 'Partial'],
            ],
            'measurement_debt' => [
                ['label' => 'Website lead event mapping incomplete', 'severity' => 'Medium'],
                ['label' => 'Messaging conversations not fully staged in CRM', 'severity' => 'Medium'],
                ['label' => 'Profile visits remain platform-only (expected)', 'severity' => 'Low'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function operations(): array
    {
        return [
            'subtitle' => 'Findings → recommendations → tasks → outcomes for Paid Social Demo stories.',
            'findings' => [
                [
                    'id' => 'meta-f-fatigue',
                    'severity' => 'high',
                    'category' => 'Creatives',
                    'title' => 'Transformation V2 shows rising frequency with weaker CTR and higher cost',
                    'status' => 'open',
                ],
                [
                    'id' => 'meta-f-lead-quality',
                    'severity' => 'high',
                    'category' => 'Measurement',
                    'title' => 'Platform Instant Form leads outpace CRM accepted leads',
                    'status' => 'open',
                ],
                [
                    'id' => 'meta-f-destination-lang',
                    'severity' => 'medium',
                    'category' => 'Funnel',
                    'title' => 'German Breast Lift campaign lands on English Website page',
                    'status' => 'open',
                ],
                [
                    'id' => 'meta-f-price-quality',
                    'severity' => 'medium',
                    'category' => 'Creatives',
                    'title' => 'Price V6 wins Meta CPL but under-delivers qualified CRM accepts',
                    'status' => 'open',
                ],
                [
                    'id' => 'meta-f-insufficient-evidence',
                    'severity' => 'low',
                    'category' => 'Creatives',
                    'title' => 'Expert V1 lacks enough delivery history for a replacement decision',
                    'status' => 'open',
                ],
            ],
            'recommendations' => [
                [
                    'id' => 'meta-r-rotate-transform',
                    'title' => 'Prepare a Trust/Expert replacement for Transformation V2',
                    'finding_id' => 'meta-f-fatigue',
                    'status' => 'accepted',
                ],
                [
                    'id' => 'meta-r-qualify-forms',
                    'title' => 'Tighten Instant Form questions that correlate with CRM reject reasons',
                    'finding_id' => 'meta-f-lead-quality',
                    'status' => 'pending',
                ],
                [
                    'id' => 'meta-r-fix-lp',
                    'title' => 'Align Breast Lift landing language with German campaign creative',
                    'finding_id' => 'meta-f-destination-lang',
                    'status' => 'accepted',
                ],
                [
                    'id' => 'meta-r-hold-expert',
                    'title' => 'Hold Expert V1 — insufficient evidence to scale or kill',
                    'finding_id' => 'meta-f-insufficient-evidence',
                    'status' => 'accepted',
                ],
            ],
            'tasks' => [
                [
                    'id' => 'meta-t-creative-brief',
                    'title' => 'Brief Trust/Expert replacement creative for Post Bariatric',
                    'status' => 'open',
                    'owner' => 'Creative',
                    'due' => '14 Aug',
                ],
                [
                    'id' => 'meta-t-form-fields',
                    'title' => 'Review Instant Form field set vs CRM reject taxonomy',
                    'status' => 'open',
                    'owner' => 'Media buyer',
                    'due' => '15 Aug',
                ],
                [
                    'id' => 'meta-t-lp-lang',
                    'title' => 'Publish German Breast Lift landing variant',
                    'status' => 'in_progress',
                    'owner' => 'Website',
                    'due' => '13 Aug',
                ],
                [
                    'id' => 'meta-t-monitor-expert',
                    'title' => 'Monitor Expert V1 through next 7 delivery days',
                    'status' => 'open',
                    'owner' => 'Media buyer',
                    'due' => '19 Aug',
                ],
            ],
            'outcomes' => [
                [
                    'task' => 'Prior Instant Form spam filter',
                    'state' => 'Improvement observed',
                    'note' => 'CRM accept rate improved on Trust-led leads after form cleanup.',
                ],
                [
                    'task' => 'Paused low-quality Audience Network experiment',
                    'state' => 'Improvement observed',
                    'note' => 'Lead quality gap narrowed slightly on Mommy Makeover.',
                ],
                [
                    'task' => 'Breast Lift destination language fix',
                    'state' => 'Awaiting follow-up',
                    'note' => 'German LP variant in progress — Website task open.',
                ],
                [
                    'task' => 'Expert V1 scale decision',
                    'state' => 'Insufficient evidence',
                    'note' => 'Delivery history still too thin for a call.',
                ],
            ],
            'finding_detail' => [
                'meta-f-fatigue' => [
                    'what' => 'Transformation V2 frequency moved 1.6 → 2.8 with CTR decline and rising cost/result.',
                    'why' => 'Continuing spend may buy fatigued impressions on the primary lead campaign.',
                    'scope' => 'camp-pb-eu · Transformation V2',
                    'evidence' => 'Demo daily creative fixtures · frequency + CTR trend',
                    'next' => 'Rotate toward Trust/Expert once replacement assets are ready (no Meta write).',
                ],
                'meta-f-lead-quality' => [
                    'what' => '122 platform leads vs 87 CRM accepted in the baseline window.',
                    'why' => 'Price-led Instant Forms inflate platform results relative to business outcomes.',
                    'scope' => 'Instant Form campaigns',
                    'evidence' => 'Meta leads · CRM demo stages',
                    'next' => 'Review form fields and creative angle mix.',
                ],
                'meta-f-destination-lang' => [
                    'what' => 'German Breast Lift ads land on an English Website page.',
                    'why' => 'Language friction can suppress Website lead quality and trust.',
                    'scope' => 'camp-bl-web',
                    'evidence' => 'Campaign language · Website destination Demo',
                    'next' => 'Complete German LP task; re-check destination consistency.',
                ],
                'meta-f-insufficient-evidence' => [
                    'what' => 'Expert V1 is new with limited spend/results.',
                    'why' => 'A kill/scale call would be speculation.',
                    'scope' => 'camp-pb-eu · Expert V1',
                    'evidence' => 'Creative delivery window too short',
                    'next' => 'Monitor — do not invent a fatigue or health score.',
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
            [
                'priority' => 'High',
                'title' => 'Creative rotation',
                'metric' => 'Transformation V2 fatigue pattern',
                'cta' => 'Open creatives',
                'tab' => 'creatives',
            ],
            [
                'priority' => 'High',
                'title' => 'Lead quality',
                'metric' => 'Platform vs CRM gap on Instant Forms',
                'cta' => 'Review measurement',
                'tab' => 'measurement',
            ],
            [
                'priority' => 'Medium',
                'title' => 'Destination language',
                'metric' => 'Breast Lift DE → EN LP',
                'cta' => 'Open funnel',
                'tab' => 'funnel',
            ],
            [
                'priority' => 'Explore',
                'title' => 'Expert angle',
                'metric' => 'Insufficient history — monitor only',
                'cta' => 'Inspect Expert V1',
                'tab' => 'creatives',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentOutcomes(): array
    {
        return [
            ['title' => 'Instant Form quality cleanup', 'state' => 'Improvement observed'],
            ['title' => 'Audience Network trim', 'state' => 'Improvement observed'],
            ['title' => 'Breast Lift language alignment', 'state' => 'Awaiting follow-up'],
            ['title' => 'Expert V1 decision', 'state' => 'Insufficient evidence'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function adSetsForCampaign(string $campaignId, int $campaignSpend, int $campaignResults, string $resultLabel): array
    {
        $shares = match ($campaignId) {
            'camp-pb-eu' => [
                ['id' => 'camp-pb-eu-as1', 'name' => 'DE Turkish 30–54 Broad', 'share' => 0.46, 'ctr' => 1.72],
                ['id' => 'camp-pb-eu-as2', 'name' => 'Lookalike Instant Form 30d', 'share' => 0.34, 'ctr' => 1.58],
                ['id' => 'camp-pb-eu-as3', 'name' => 'Engagers 90d', 'share' => 0.20, 'ctr' => 1.81],
            ],
            'camp-mm-eu' => [
                ['id' => 'camp-mm-eu-as1', 'name' => 'DE Turkish Moms Broad', 'share' => 0.55, 'ctr' => 1.80],
                ['id' => 'camp-mm-eu-as2', 'name' => 'LAL Mommy leads', 'share' => 0.45, 'ctr' => 1.62],
            ],
            'camp-bl-web' => [
                ['id' => 'camp-bl-web-as1', 'name' => 'DE Interest Aesthetic', 'share' => 0.62, 'ctr' => 1.90],
                ['id' => 'camp-bl-web-as2', 'name' => 'Site visitors excl converters', 'share' => 0.38, 'ctr' => 1.70],
            ],
            'camp-msg-retarget' => [
                ['id' => 'camp-msg-retarget-as1', 'name' => 'Site 14d · Messaging', 'share' => 0.70, 'ctr' => 1.35],
                ['id' => 'camp-msg-retarget-as2', 'name' => 'IG engagers 30d', 'share' => 0.30, 'ctr' => 1.28],
            ],
            'camp-aware-ig' => [
                ['id' => 'camp-aware-ig-as1', 'name' => 'Broad DE Turkish IG', 'share' => 1.0, 'ctr' => 1.02],
            ],
            'camp-retarget' => [
                ['id' => 'camp-retarget-as1', 'name' => 'Form engagers 30d', 'share' => 0.60, 'ctr' => 1.70],
                ['id' => 'camp-retarget-as2', 'name' => 'Video viewers 50%', 'share' => 0.40, 'ctr' => 1.55],
            ],
            default => [
                ['id' => $campaignId.'-as1', 'name' => 'Default ad set', 'share' => 1.0, 'ctr' => 1.5],
            ],
        };

        $rows = [];
        $allocatedSpend = 0;
        $allocatedResults = 0;
        foreach ($shares as $index => $share) {
            $isLast = $index === count($shares) - 1;
            $spend = $isLast ? ($campaignSpend - $allocatedSpend) : (int) round($campaignSpend * $share['share']);
            $results = $isLast ? ($campaignResults - $allocatedResults) : (int) round($campaignResults * $share['share']);
            $allocatedSpend += $spend;
            $allocatedResults += $results;
            $rows[] = [
                'id' => $share['id'],
                'name' => $share['name'],
                'status' => self::CAMPAIGN_BASELINES[$campaignId]['status'] ?? 'ACTIVE',
                'spend' => $spend,
                'results' => max(0, $results),
                'result_label' => $resultLabel,
                'cost_result' => $results > 0 ? (int) round($spend / $results) : null,
                'ctr' => $share['ctr'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function audienceForCampaign(string $campaignId, int $spend): array
    {
        $base = self::audience(['spend' => $spend, 'impressions' => 0, 'reach' => 0, 'link_clicks' => 0]);
        $base['campaign_id'] = $campaignId;
        if ($campaignId === 'camp-bl-web') {
            $base['gaps'][] = 'Configured German language; landing page English — destination consistency risk.';
        }

        return $base;
    }

    /**
     * Deterministic daily series for one campaign date (normalized later via aggregate).
     *
     * @return array{spend: float, results: float, impressions: float, reach: float, link_clicks: float}
     */
    public static function rawDayWeight(string $campaignId, string $date): array
    {
        $hash = crc32($date.'|'.$campaignId.'|meta-demo');
        $unit = ($hash % 10000) / 10000; // 0..0.9999
        $dow = (int) Carbon::parse($date, DemoPeriod::TIMEZONE)->dayOfWeekIso; // 1..7
        $weekend = $dow >= 6 ? 0.88 : 1.0;
        $wave = 0.82 + 0.36 * abs(sin(($hash % 360) * M_PI / 180));

        return [
            'spend' => max(0.15, $unit * $weekend * $wave),
            'results' => max(0.10, (0.35 + $unit * 0.9) * $weekend * $wave),
            'impressions' => max(0.20, (0.5 + $unit) * $weekend),
            'reach' => max(0.20, (0.45 + $unit * 0.9) * $weekend),
            'link_clicks' => max(0.15, (0.4 + $unit) * $weekend * $wave),
        ];
    }

    /**
     * @return array{spend: int, results: int, impressions: int, reach: int, link_clicks: int, frequency: float, ctr: float}
     */
    public static function aggregateCampaign(string $campaignId, string $start, string $end): array
    {
        $base = self::CAMPAIGN_BASELINES[$campaignId] ?? null;
        if ($base === null) {
            return [
                'spend' => 0,
                'results' => 0,
                'impressions' => 0,
                'reach' => 0,
                'link_clicks' => 0,
                'frequency' => 0.0,
                'ctr' => 0.0,
            ];
        }

        $anchor = DemoPeriod::anchor();
        $baselineStart = $anchor->copy()->subDays(27)->toDateString();
        $baselineEnd = $anchor->toDateString();

        $baselineWeights = self::sumWeights($campaignId, $baselineStart, $baselineEnd);
        $rangeWeights = self::sumWeights($campaignId, $start, $end);

        $scale = static function (float $baselineTotal, float $rangeTotal, int $baselineValue): int {
            if ($baselineTotal <= 0.0) {
                return 0;
            }

            return (int) max(0, round($baselineValue * ($rangeTotal / $baselineTotal)));
        };

        $spend = $scale($baselineWeights['spend'], $rangeWeights['spend'], (int) $base['spend']);
        $results = $scale($baselineWeights['results'], $rangeWeights['results'], (int) $base['results']);
        $impressions = $scale($baselineWeights['impressions'], $rangeWeights['impressions'], (int) $base['impressions']);
        $reach = $scale($baselineWeights['reach'], $rangeWeights['reach'], (int) $base['reach']);
        $linkClicks = $scale($baselineWeights['link_clicks'], $rangeWeights['link_clicks'], (int) $base['link_clicks']);

        $days = max(1, count(self::daysInRange($start, $end)));
        $baselineDays = 28;
        $freq = (float) $base['frequency'] * (0.92 + min(0.2, abs($days - $baselineDays) * 0.004));
        $ctr = $impressions > 0 ? round(($linkClicks / $impressions) * 100, 2) : (float) $base['ctr'];

        return [
            'spend' => $spend,
            'results' => $results,
            'impressions' => $impressions,
            'reach' => $reach,
            'link_clicks' => $linkClicks,
            'frequency' => round($freq, 2),
            'ctr' => $ctr,
        ];
    }

    /**
     * @return array{spend: float, results: float, impressions: float, reach: float, link_clicks: float}
     */
    private static function sumWeights(string $campaignId, string $start, string $end): array
    {
        $sum = [
            'spend' => 0.0,
            'results' => 0.0,
            'impressions' => 0.0,
            'reach' => 0.0,
            'link_clicks' => 0.0,
        ];
        foreach (self::daysInRange($start, $end) as $date) {
            $w = self::rawDayWeight($campaignId, $date);
            foreach ($sum as $key => $_) {
                $sum[$key] += $w[$key];
            }
        }

        return $sum;
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

    /**
     * @return array{spend: int, leads: int, messaging: int}
     */
    private static function accountDay(string $date): array
    {
        $spend = 0;
        $leads = 0;
        $messaging = 0;
        foreach (array_keys(self::CAMPAIGN_BASELINES) as $campaignId) {
            $day = self::scaledDay($campaignId, $date);
            $spend += $day['spend'];
            if (self::CAMPAIGN_BASELINES[$campaignId]['result_label'] === 'Leads') {
                $leads += $day['results'];
            }
            if (self::CAMPAIGN_BASELINES[$campaignId]['result_label'] === 'Messaging conversations') {
                $messaging += $day['results'];
            }
        }

        return [
            'spend' => $spend,
            'leads' => $leads,
            'messaging' => $messaging,
        ];
    }

    /**
     * @return array{spend: int, results: int}
     */
    private static function scaledDay(string $campaignId, string $date): array
    {
        $base = self::CAMPAIGN_BASELINES[$campaignId];
        $anchor = DemoPeriod::anchor();
        $baselineStart = $anchor->copy()->subDays(27)->toDateString();
        $baselineEnd = $anchor->toDateString();
        $baselineWeights = self::sumWeights($campaignId, $baselineStart, $baselineEnd);
        $w = self::rawDayWeight($campaignId, $date);

        $spend = $baselineWeights['spend'] > 0
            ? (int) round($base['spend'] * ($w['spend'] / $baselineWeights['spend']))
            : 0;
        $results = $baselineWeights['results'] > 0
            ? (int) round($base['results'] * ($w['results'] / $baselineWeights['results']))
            : 0;

        return ['spend' => $spend, 'results' => $results];
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return array{spend: int, impressions: int, reach: int, link_clicks: int, by_label: array<string, array{label: string, results: int, spend: int}>}
     */
    private static function accountTotals(array $campaigns): array
    {
        $totals = [
            'spend' => 0,
            'impressions' => 0,
            'reach' => 0,
            'link_clicks' => 0,
            'by_label' => [],
        ];
        foreach ($campaigns as $campaign) {
            $totals['spend'] += (int) $campaign['spend'];
            $totals['impressions'] += (int) $campaign['impressions'];
            $totals['reach'] += (int) $campaign['reach'];
            $totals['link_clicks'] += (int) $campaign['link_clicks'];
            $label = (string) $campaign['result_label'];
            if (! isset($totals['by_label'][$label])) {
                $totals['by_label'][$label] = ['label' => $label, 'results' => 0, 'spend' => 0];
            }
            $totals['by_label'][$label]['results'] += (int) $campaign['results'];
            $totals['by_label'][$label]['spend'] += (int) $campaign['spend'];
        }

        return $totals;
    }

    /**
     * @param  list<array{label: string, share: int}>  $rows
     * @return list<array{label: string, share: int, spend: int}>
     */
    private static function barRows(array $rows, int $spend): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'label' => $row['label'],
                'share' => $row['share'],
                'spend' => (int) round($spend * ($row['share'] / 100)),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return array<string, mixed>|null
     */
    private static function findCampaign(array $campaigns, string $id): ?array
    {
        foreach ($campaigns as $campaign) {
            if ($campaign['id'] === $id) {
                return $campaign;
            }
        }

        return null;
    }

    private static function pctDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private static function formatDelta(float $delta): string
    {
        $prefix = $delta > 0 ? '+' : '';

        return $prefix.number_format($delta, 1).'%';
    }
}
