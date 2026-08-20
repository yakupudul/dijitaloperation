<?php

namespace App\Support\Demo;

use Carbon\Carbon;

/**
 * Deterministic Demo Mode fixtures for the Google Analytics Measurement Intelligence workspace.
 * No live GA4 API. No runtime randomness — crc32/hash only.
 *
 * Daily property series cover 90 days ending {@see DemoPeriod::ANCHOR_DATE}
 * so custom ranges aggregate honestly from day buckets.
 */
final class Ga4WorkspaceFixtures
{
    /** last_28 baseline users (property level). */
    private const int BASELINE_USERS = 18420;

    /** last_28 baseline sessions (property level). */
    private const int BASELINE_SESSIONS = 24860;

    /**
     * last_28 mapped business actions (Lead Form + Phone where mapped).
     * WhatsApp / Appointment remain Not mapped — missing ≠ zero.
     */
    private const int BASELINE_MAPPED_ACTIONS = 684;

    /** last_28 Lead Form generate_lead events (mapped · Healthy). */
    private const int BASELINE_LEAD_FORM = 498;

    /** last_28 phone_click events (mapped · Review). */
    private const int BASELINE_PHONE = 186;

    /** last_28 whatsapp_click events (observed · Not mapped). */
    private const int BASELINE_WHATSAPP = 214;

    /** last_28 form_start events (funnel · not a business action). */
    private const int BASELINE_FORM_START = 1420;

    /**
     * Channel session share of last_28 baseline (must sum ~1.0).
     *
     * @var array<string, float>
     */
    private const array CHANNEL_SHARES = [
        'Organic Search' => 0.34,
        'Paid Social' => 0.24,
        'Paid Search' => 0.19,
        'Direct' => 0.14,
        'Referral' => 0.06,
        'Cross-network' => 0.03,
    ];

    /**
     * Landing page session share of last_28 baseline.
     *
     * @var array<string, array{share: float, role: string, title: string, engaged: float, action_rate: float}>
     */
    private const array LANDING_PAGES = [
        '/implant' => [
            'share' => 0.28,
            'role' => 'Service / Product',
            'title' => 'Dental Implants in Ankara',
            'engaged' => 0.54,
            'action_rate' => 0.0142,
        ],
        '/post-bariatric' => [
            'share' => 0.18,
            'role' => 'Service / Product',
            'title' => 'Post-Bariatric Dentistry',
            'engaged' => 0.61,
            'action_rate' => 0.0132,
        ],
        '/' => [
            'share' => 0.16,
            'role' => 'Home',
            'title' => 'Atlas Dental Ankara',
            'engaged' => 0.49,
            'action_rate' => 0.0058,
        ],
        '/contact' => [
            'share' => 0.10,
            'role' => 'Conversion / Contact',
            'title' => 'Contact',
            'engaged' => 0.72,
            'action_rate' => 0.0148,
        ],
        '/team' => [
            'share' => 0.05,
            'role' => 'Team / Expert',
            'title' => 'Our specialists',
            'engaged' => 0.58,
            'action_rate' => 0.0021,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function workspace(string $preset = 'last_28', ?string $start = null, ?string $end = null): array
    {
        if (! DemoPeriod::inFixtureAnchorContext()) {
            return DemoPeriod::usingFixtureAnchor(fn (): array => self::workspace($preset, $start, $end));
        }

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

        $businessActions = self::businessActionsMatrix($totals);
        $measurement = self::measurement($totals, $businessActions);

        return [
            'period_label' => $f['label'],
            'period_days' => $bounds['days'],
            'period_start' => $rangeStart,
            'period_end' => $rangeEnd,
            'compare_label' => 'vs '.$prev['label'],
            'demo_boundary' => 'Demo Mode · product vision fixtures — no live GA4 API or write',
            'identity' => self::identity(),
            'freshness' => self::freshness(),
            'glance' => self::glance($totals, $compare, $f),
            'needs_attention' => self::needsAttention(),
            'performance_trend' => self::performanceTrend($rangeStart, $rangeEnd),
            'acquisition_mix' => self::acquisitionMix($totals),
            'landing_pulse' => self::landingPulse($totals),
            'business_actions' => $businessActions,
            'measurement' => $measurement,
            'acquisition' => self::acquisition($totals),
            'behavior' => self::behavior($totals),
            'journeys' => self::journeys($totals),
            'relationships' => self::relationships(),
            'operations' => self::operations(),
            'recent_outcomes' => self::recentOutcomes(),
            'opportunities' => self::opportunities(),
            'narrative' => $f['narrative'] ?? null,
            'missing_note' => 'Missing ≠ zero — Not mapped / Unavailable means the signal is absent, not a measured 0.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'eyebrow' => 'Google Analytics',
            'title' => 'Atlas Dental — GA4',
            'brand' => 'Atlas Dental Ankara',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'website_asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
            'google_ads_asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
            'meta_asset_id' => DemoCatalog::META_ASSET_ID,
            'ga4_asset_id' => DemoCatalog::GA4_ASSET_ID,
            'relationship_line' => 'Measures · Atlas Dental Website',
            'status' => 'Connected',
            'freshness' => 'Data through Aug 12',
            'reporting_timezone' => DemoPeriod::TIMEZONE,
            'property_id' => '123456789',
            'measurement_id' => 'G-DEMOATLAS',
            'property_name' => 'Atlas Dental GA4',
            'stream_name' => 'Atlas Dental Web',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function freshness(): array
    {
        return [
            ['source' => 'GA4', 'age' => '2h', 'detail' => 'Last successful collection · Aug 12 22:18 Europe/Berlin', 'state' => 'current'],
            ['source' => 'Website', 'age' => '4h', 'detail' => 'Content roles + CTA inventory · Demo', 'state' => 'current'],
            ['source' => 'Google Ads', 'age' => '2h', 'detail' => 'Related paid acquisition asset', 'state' => 'current'],
            ['source' => 'Meta Ads', 'age' => '2h', 'detail' => 'Related paid social asset', 'state' => 'current'],
            ['source' => 'Brand Context', 'age' => 'Current', 'detail' => 'Operator maintained', 'state' => 'current'],
        ];
    }

    /**
     * @param  array{users: int, sessions: int, lead_form: int, phone: int, whatsapp: int, mapped_actions: int, form_start: int}  $totals
     * @param  array{users: int, sessions: int, mapped_actions: int}  $compare
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function glance(array $totals, array $compare, array $f): array
    {
        $usersDelta = self::pctDelta((int) $totals['users'], (int) $compare['users']);
        $sessionsDelta = self::pctDelta((int) $totals['sessions'], (int) $compare['sessions']);
        $actionsDelta = self::pctDelta((int) $totals['mapped_actions'], (int) $compare['mapped_actions']);

        return [
            'users' => [
                'value' => number_format((int) $totals['users']),
                'raw' => (int) $totals['users'],
                'secondary' => self::formatDelta($usersDelta).' vs previous period',
                'tone' => $usersDelta >= 0 ? 'positive' : 'warning',
            ],
            'sessions' => [
                'value' => number_format((int) $totals['sessions']),
                'raw' => (int) $totals['sessions'],
                'secondary' => self::formatDelta($sessionsDelta).' · '.$f['label'],
                'tone' => 'neutral',
            ],
            'business_actions' => [
                'value' => number_format((int) $totals['mapped_actions']),
                'raw' => (int) $totals['mapped_actions'],
                'secondary' => self::formatDelta($actionsDelta).' · Lead Form + Phone mapped',
                'tone' => $actionsDelta >= 0 ? 'positive' : 'warning',
                'note' => 'WhatsApp and Appointment remain Not mapped — not counted as zero.',
            ],
            'measurement_state' => [
                'value' => 'Partial · debt present',
                'secondary' => '1 Healthy · 1 Review · 2 Not mapped',
                'tone' => 'warning',
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
                'id' => 'att-lead-interruption',
                'severity' => 'Critical',
                'title' => 'Lead Form signal interruption candidate',
                'metric' => '36h without generate_lead while sessions continued',
                'scope' => 'Property · Web stream',
                'action' => 'Review',
                'tab' => 'measurement',
                'finding_id' => 'ga4-f-lead-interruption',
            ],
            [
                'id' => 'att-whatsapp-map',
                'severity' => 'High',
                'title' => 'WhatsApp event not mapped',
                'metric' => 'whatsapp_click observed · business action Not mapped',
                'scope' => 'Site-wide CTA inventory',
                'action' => 'Map action',
                'tab' => 'measurement',
                'finding_id' => 'ga4-f-whatsapp-unmapped',
            ],
            [
                'id' => 'att-utm-hygiene',
                'severity' => 'High',
                'title' => 'utm_campaign unavailable rising',
                'metric' => '6% → 18% of sessions lack campaign',
                'scope' => 'Paid + partner traffic',
                'action' => 'Inspect',
                'tab' => 'acquisition',
                'finding_id' => 'ga4-f-utm-hygiene',
            ],
            [
                'id' => 'att-self-referral',
                'severity' => 'Medium',
                'title' => 'Self-referral review candidate',
                'metric' => 'atlasdental.example / referral material',
                'scope' => 'Referral channel',
                'action' => 'Review',
                'tab' => 'measurement',
                'finding_id' => 'ga4-f-self-referral',
            ],
            [
                'id' => 'att-phone-review',
                'severity' => 'Medium',
                'title' => 'Phone business action needs review',
                'metric' => 'phone_click mapped · signal sparse vs CTA presence',
                'scope' => 'Contact + service pages',
                'action' => 'Inspect',
                'tab' => 'measurement',
                'finding_id' => 'ga4-f-phone-review',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function performanceTrend(string $start, string $end): array
    {
        $days = self::daysInRange($start, $end);
        $labels = [];
        $sessions = [];
        $actions = [];

        foreach ($days as $date) {
            $day = self::scaledDay($date);
            $labels[] = Carbon::parse($date, DemoPeriod::TIMEZONE)->format('M j');
            $sessions[] = $day['sessions'];
            $actions[] = $day['mapped_actions'];
        }

        // Cap chart density for very long ranges while preserving order.
        if (count($labels) > 42) {
            $step = (int) ceil(count($labels) / 28);
            $sampledLabels = [];
            $sampledSessions = [];
            $sampledActions = [];
            foreach ($labels as $i => $label) {
                if ($i % $step !== 0) {
                    continue;
                }
                $sampledLabels[] = $label;
                $sampledSessions[] = $sessions[$i];
                $sampledActions[] = $actions[$i];
            }
            $labels = $sampledLabels;
            $sessions = $sampledSessions;
            $actions = $sampledActions;
        }

        return [
            'labels' => $labels,
            'sessions' => $sessions,
            'business_actions' => $actions,
            'note' => 'Sessions and mapped business actions · daily Demo fixtures',
        ];
    }

    /**
     * @param  array{sessions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function acquisitionMix(array $totals): array
    {
        $sessions = (int) $totals['sessions'];
        $rows = [];
        $allocated = 0;
        $keys = array_keys(self::CHANNEL_SHARES);
        $last = count($keys) - 1;

        foreach ($keys as $index => $channel) {
            $share = self::CHANNEL_SHARES[$channel];
            $value = $index === $last
                ? max(0, $sessions - $allocated)
                : (int) round($sessions * $share);
            $allocated += $value;
            $rows[] = [
                'channel' => $channel,
                'sessions' => $value,
                'share_pct' => $sessions > 0 ? round(($value / $sessions) * 100, 1) : 0.0,
                'bar' => $sessions > 0 ? (int) round(($value / $sessions) * 100) : 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

        return $rows;
    }

    /**
     * @param  array{sessions: int, mapped_actions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function landingPulse(array $totals): array
    {
        $sessions = (int) $totals['sessions'];
        $rows = [];

        foreach (self::LANDING_PAGES as $path => $meta) {
            $pageSessions = (int) round($sessions * $meta['share']);
            $engaged = (int) round($pageSessions * $meta['engaged']);
            $actions = (int) round($pageSessions * $meta['action_rate']);
            $rows[] = [
                'path' => $path,
                'title' => $meta['title'],
                'content_role' => $meta['role'],
                'sessions' => $pageSessions,
                'engaged_sessions' => $engaged,
                'engaged_rate' => (int) round($meta['engaged'] * 100),
                'mapped_actions' => $actions,
                'website_asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
                'attention' => match ($path) {
                    '/implant' => 'Mobile LCP Finding on Website',
                    '/contact' => 'Local phone consistency Finding',
                    default => null,
                },
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

        return $rows;
    }

    /**
     * @param  array{lead_form: int, phone: int, whatsapp: int, form_start: int, mapped_actions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function businessActionsMatrix(array $totals): array
    {
        return [
            [
                'action' => 'Lead Form',
                'ga4_event' => 'generate_lead',
                'event_count' => (int) $totals['lead_form'],
                'role' => 'Primary',
                'state' => 'Healthy',
                'mapping' => 'Mapped',
                'note' => 'Primary Website enquiry signal',
            ],
            [
                'action' => 'WhatsApp',
                'ga4_event' => 'whatsapp_click',
                'event_count' => (int) $totals['whatsapp'],
                'role' => 'Secondary',
                'state' => 'Not mapped',
                'mapping' => 'Not mapped',
                'note' => 'Event exists · business action unavailable until mapped',
            ],
            [
                'action' => 'Phone',
                'ga4_event' => 'phone_click',
                'event_count' => (int) $totals['phone'],
                'role' => 'Secondary',
                'state' => 'Review',
                'mapping' => 'Mapped',
                'note' => 'Mapped but volume sparse vs CTA presence — review instrumentation',
            ],
            [
                'action' => 'Appointment',
                'ga4_event' => null,
                'event_count' => null,
                'role' => '—',
                'state' => 'Not mapped',
                'mapping' => 'Not mapped',
                'note' => 'Unavailable — no discovered event mapped to Appointment',
            ],
        ];
    }

    /**
     * @param  array{lead_form: int, phone: int, whatsapp: int, form_start: int, sessions: int, mapped_actions: int}  $totals
     * @param  list<array<string, mixed>>  $matrix
     * @return array<string, mixed>
     */
    public static function measurement(array $totals, array $matrix): array
    {
        $sessions = max(1, (int) $totals['sessions']);
        $utmUnavailablePct = 18;
        $utmUnavailableSessions = (int) round($sessions * ($utmUnavailablePct / 100));

        return [
            'subtitle' => 'Whether GA4 events connect to trustworthy business outcomes for Atlas Dental Website.',
            'missing_note' => 'Not mapped / Unavailable ≠ measured zero.',
            'business_actions' => $matrix,
            'events' => [
                ['event' => 'generate_lead', 'count' => (int) $totals['lead_form'], 'mapped_action' => 'Lead Form', 'state' => 'Healthy'],
                ['event' => 'whatsapp_click', 'count' => (int) $totals['whatsapp'], 'mapped_action' => 'Not mapped', 'state' => 'Observed'],
                ['event' => 'phone_click', 'count' => (int) $totals['phone'], 'mapped_action' => 'Phone', 'state' => 'Review'],
                ['event' => 'form_start', 'count' => (int) $totals['form_start'], 'mapped_action' => '—', 'state' => 'Funnel only'],
                ['event' => 'appointment_request', 'count' => null, 'mapped_action' => 'Appointment', 'state' => 'Unavailable'],
            ],
            'streams' => [
                [
                    'name' => 'Atlas Dental Web',
                    'stream_id' => 'demo-stream-1001',
                    'measurement_id' => 'G-DEMOATLAS',
                    'type' => 'Web',
                    'status' => 'Receiving',
                    'last_hit' => 'Aug 12 22:05 Europe/Berlin',
                ],
            ],
            'data_quality' => [
                ['check' => 'Primary Lead Form mapping', 'state' => 'Healthy', 'detail' => 'generate_lead → Lead Form'],
                ['check' => 'WhatsApp business action', 'state' => 'Not mapped', 'detail' => 'whatsapp_click observed without action binding'],
                ['check' => 'Phone instrumentation', 'state' => 'Review', 'detail' => 'Mapped · sparse vs CTA coverage'],
                ['check' => 'Appointment goal', 'state' => 'Unavailable', 'detail' => 'No event discovered for Appointment'],
                ['check' => 'utm_campaign coverage', 'state' => 'Needs review', 'detail' => $utmUnavailablePct.'% sessions · was 6% prior window'],
                ['check' => 'Self-referral exclusion', 'state' => 'Review candidate', 'detail' => 'atlasdental.example appears as referral'],
            ],
            'interruptions' => [
                [
                    'id' => 'int-lead-36h',
                    'title' => 'Lead Form signal interruption candidate',
                    'detail' => 'generate_lead was silent for ~36 hours while sessions continued on /implant and /contact.',
                    'window' => 'Aug 4 06:00 – Aug 5 18:00 Europe/Berlin',
                    'finding_id' => 'ga4-f-lead-interruption',
                    'state' => 'Improvement observed later',
                ],
            ],
            'duplicates' => [
                [
                    'title' => 'Possible duplicate lead counting across Ads + GA4',
                    'detail' => 'Google Ads lead conversion and GA4 generate_lead may represent the same enquiry — review before summing.',
                    'state' => 'Review',
                ],
            ],
            'utm_hygiene' => [
                'unavailable_pct' => $utmUnavailablePct,
                'prior_unavailable_pct' => 6,
                'unavailable_sessions' => $utmUnavailableSessions,
                'trend' => '6% → 18%',
                'note' => 'Campaign dimension unavailable increased — tagging debt, not a traffic score.',
                'finding_id' => 'ga4-f-utm-hygiene',
            ],
            'referrals' => [
                [
                    'source' => 'atlasdental.example',
                    'medium' => 'referral',
                    'sessions' => (int) round($sessions * 0.018),
                    'state' => 'Self-referral review candidate',
                    'finding_id' => 'ga4-f-self-referral',
                ],
                [
                    'source' => 'chatgpt.com',
                    'medium' => 'referral',
                    'sessions' => (int) round($sessions * 0.002),
                    'state' => 'Observed',
                    'finding_id' => null,
                ],
                [
                    'source' => 'partner-clinic.example',
                    'medium' => 'referral',
                    'sessions' => (int) round($sessions * 0.011),
                    'state' => 'Observed',
                    'finding_id' => null,
                ],
            ],
            'trust_chips' => [
                ['label' => 'Lead Form', 'state' => 'Healthy'],
                ['label' => 'WhatsApp', 'state' => 'Not mapped'],
                ['label' => 'Phone', 'state' => 'Review'],
                ['label' => 'Appointment', 'state' => 'Unavailable'],
            ],
        ];
    }

    /**
     * @param  array{sessions: int, mapped_actions: int, lead_form: int}  $totals
     * @return array<string, mixed>
     */
    public static function acquisition(array $totals): array
    {
        $sessions = (int) $totals['sessions'];
        $actions = (int) $totals['mapped_actions'];

        $channels = self::acquisitionMix($totals);
        foreach ($channels as &$row) {
            $row['mapped_actions'] = (int) round($actions * ($row['share_pct'] / 100));
            $row['related'] = match ($row['channel']) {
                'Paid Search' => [
                    'asset' => 'Google Ads',
                    'asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                    'route' => 'operator.google-ads.overview',
                ],
                'Paid Social' => [
                    'asset' => 'Meta Ads',
                    'asset_id' => DemoCatalog::META_ASSET_ID,
                    'route' => 'operator.meta.overview',
                ],
                'Organic Search' => [
                    'asset' => 'Search Console',
                    'asset_id' => DemoCatalog::GSC_ASSET_ID,
                    'route' => 'operator.search-console',
                ],
                default => null,
            };
        }
        unset($row);

        $sourceMedium = [
            ['source_medium' => 'google / organic', 'sessions' => (int) round($sessions * 0.30), 'mapped_actions' => (int) round($actions * 0.28)],
            ['source_medium' => 'facebook / paid', 'sessions' => (int) round($sessions * 0.16), 'mapped_actions' => (int) round($actions * 0.14)],
            ['source_medium' => 'google / cpc', 'sessions' => (int) round($sessions * 0.15), 'mapped_actions' => (int) round($actions * 0.22)],
            ['source_medium' => '(direct) / (none)', 'sessions' => (int) round($sessions * 0.14), 'mapped_actions' => (int) round($actions * 0.10)],
            ['source_medium' => 'instagram / paid', 'sessions' => (int) round($sessions * 0.08), 'mapped_actions' => (int) round($actions * 0.07)],
            ['source_medium' => 'atlasdental.example / referral', 'sessions' => (int) round($sessions * 0.018), 'mapped_actions' => (int) round($actions * 0.01), 'attention' => 'Self-referral review'],
            ['source_medium' => '(not set) / (not set)', 'sessions' => (int) round($sessions * 0.04), 'mapped_actions' => null, 'attention' => 'utm unavailable'],
        ];

        $campaigns = [
            [
                'campaign' => 'Post Bariatric — Diaspora Lead',
                'source' => 'facebook / paid',
                'sessions' => (int) round($sessions * 0.09),
                'mapped_actions' => (int) round($actions * 0.08),
                'related_asset' => 'Meta Ads',
                'related_asset_id' => DemoCatalog::META_ASSET_ID,
                'route' => 'operator.meta.overview',
            ],
            [
                'campaign' => 'Implant — TR Search',
                'source' => 'google / cpc',
                'sessions' => (int) round($sessions * 0.08),
                'mapped_actions' => (int) round($actions * 0.12),
                'related_asset' => 'Google Ads',
                'related_asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                'route' => 'operator.google-ads.overview',
            ],
            [
                'campaign' => 'Brand — Atlas Dental',
                'source' => 'google / cpc',
                'sessions' => (int) round($sessions * 0.04),
                'mapped_actions' => (int) round($actions * 0.05),
                'related_asset' => 'Google Ads',
                'related_asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                'route' => 'operator.google-ads.overview',
            ],
            [
                'campaign' => '(not set)',
                'source' => 'mixed',
                'sessions' => (int) round($sessions * 0.18),
                'mapped_actions' => null,
                'related_asset' => null,
                'related_asset_id' => null,
                'route' => null,
                'attention' => 'utm_campaign unavailable · 6% → 18%',
                'state' => 'Unavailable',
            ],
        ];

        return [
            'channels' => $channels,
            'source_medium' => $sourceMedium,
            'campaigns' => $campaigns,
            'utm_note' => 'Campaign Unavailable rose 6% → 18% — tagging hygiene Finding, not a quality score.',
        ];
    }

    /**
     * @param  array{sessions: int, mapped_actions: int}  $totals
     * @return array<string, mixed>
     */
    public static function behavior(array $totals): array
    {
        return [
            'subtitle' => 'Landing behaviour enriched with Website content roles — not a generic page dump.',
            'landing_pages' => self::landingPulse($totals),
            'engagement' => [
                ['metric' => 'Engagement rate', 'value' => '59.6%', 'state' => 'Measured'],
                ['metric' => 'Avg engagement time', 'value' => '1m 42s', 'state' => 'Measured'],
                ['metric' => 'Views / session', 'value' => '2.4', 'state' => 'Measured'],
                ['metric' => 'Appointment completion', 'value' => 'Unavailable', 'state' => 'Not mapped'],
            ],
            'devices' => [
                ['device' => 'Mobile', 'share_pct' => 68, 'sessions' => (int) round($totals['sessions'] * 0.68)],
                ['device' => 'Desktop', 'share_pct' => 28, 'sessions' => (int) round($totals['sessions'] * 0.28)],
                ['device' => 'Tablet', 'share_pct' => 4, 'sessions' => (int) round($totals['sessions'] * 0.04)],
            ],
        ];
    }

    /**
     * @param  array{sessions: int, mapped_actions: int}  $totals
     * @return list<array<string, mixed>>
     */
    public static function journeys(array $totals): array
    {
        $sessions = (int) $totals['sessions'];

        return [
            [
                'path' => 'Home → Implant → Contact',
                'steps' => ['/', '/implant', '/contact'],
                'sessions' => (int) round($sessions * 0.042),
                'mapped_actions' => (int) round($totals['mapped_actions'] * 0.11),
                'roles' => ['Home', 'Service / Product', 'Conversion / Contact'],
            ],
            [
                'path' => 'Paid → Implant → Lead Form',
                'steps' => ['/implant'],
                'sessions' => (int) round($sessions * 0.086),
                'mapped_actions' => (int) round($totals['mapped_actions'] * 0.22),
                'roles' => ['Service / Product'],
                'note' => 'Paid landing · Website LCP Finding present',
            ],
            [
                'path' => 'Post-bariatric → Contact',
                'steps' => ['/post-bariatric', '/contact'],
                'sessions' => (int) round($sessions * 0.028),
                'mapped_actions' => (int) round($totals['mapped_actions'] * 0.09),
                'roles' => ['Service / Product', 'Conversion / Contact'],
            ],
            [
                'path' => 'Team → Contact',
                'steps' => ['/team', '/contact'],
                'sessions' => (int) round($sessions * 0.012),
                'mapped_actions' => (int) round($totals['mapped_actions'] * 0.03),
                'roles' => ['Team / Expert', 'Conversion / Contact'],
            ],
            [
                'path' => 'Organic → Implant → WhatsApp click',
                'steps' => ['/implant'],
                'sessions' => (int) round($sessions * 0.019),
                'mapped_actions' => null,
                'roles' => ['Service / Product'],
                'note' => 'whatsapp_click observed · business action Not mapped',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function relationships(): array
    {
        return [
            'measures' => [
                [
                    'asset' => 'Atlas Dental Website',
                    'asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
                    'relationship' => 'Measures',
                    'detail' => 'Acquisition, landing behaviour, configured key events',
                    'route' => 'operator.website',
                ],
            ],
            'provides_evidence_to' => [
                [
                    'asset' => 'Google Ads',
                    'asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                    'detail' => 'Landing behaviour + conversion evidence for paid search',
                    'route' => 'operator.google-ads.overview',
                ],
                [
                    'asset' => 'Meta Ads',
                    'asset_id' => DemoCatalog::META_ASSET_ID,
                    'detail' => 'Website destination behaviour for paid social',
                    'route' => 'operator.meta.overview',
                ],
                [
                    'asset' => 'Website Diagnosis / Findings',
                    'asset_id' => DemoCatalog::WEBSITE_ASSET_ID,
                    'detail' => 'Measurement debt and interruption Findings',
                    'route' => 'operator.website',
                ],
            ],
            'technical_connection' => [
                'type' => 'GA4 property binding',
                'property_id' => '123456789',
                'measurement_id' => 'G-DEMOATLAS',
                'status' => 'Connected',
                'note' => 'Demo Mode · fake IDs · no live credentials',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function operations(): array
    {
        return [
            'subtitle' => 'Measurement findings, decisions, work and observed outcomes.',
            'findings' => [
                [
                    'id' => 'ga4-f-lead-interruption',
                    'severity' => 'critical',
                    'category' => 'Measurement',
                    'title' => 'Lead Form signal interruption candidate (~36h) while sessions continued',
                    'status' => 'open',
                ],
                [
                    'id' => 'ga4-f-whatsapp-unmapped',
                    'severity' => 'high',
                    'category' => 'Measurement',
                    'title' => 'WhatsApp click event exists but business action is Not mapped',
                    'status' => 'open',
                ],
                [
                    'id' => 'ga4-f-utm-hygiene',
                    'severity' => 'high',
                    'category' => 'Acquisition',
                    'title' => 'utm_campaign unavailable share increased from 6% to 18%',
                    'status' => 'open',
                ],
                [
                    'id' => 'ga4-f-self-referral',
                    'severity' => 'medium',
                    'category' => 'Data quality',
                    'title' => 'Self-referral review candidate for atlasdental.example',
                    'status' => 'open',
                ],
                [
                    'id' => 'ga4-f-phone-review',
                    'severity' => 'medium',
                    'category' => 'Measurement',
                    'title' => 'Phone business action mapped but requires instrumentation review',
                    'status' => 'open',
                ],
            ],
            'recommendations' => [
                [
                    'id' => 'ga4-r-map-whatsapp',
                    'title' => 'Map whatsapp_click to the WhatsApp business action',
                    'finding_id' => 'ga4-f-whatsapp-unmapped',
                    'status' => 'accepted',
                ],
                [
                    'id' => 'ga4-r-utm',
                    'title' => 'Review paid and partner UTM campaign tagging',
                    'finding_id' => 'ga4-f-utm-hygiene',
                    'status' => 'pending',
                ],
                [
                    'id' => 'ga4-r-self-ref',
                    'title' => 'Confirm self-referral exclusion for atlasdental.example',
                    'finding_id' => 'ga4-f-self-referral',
                    'status' => 'pending',
                ],
                [
                    'id' => 'ga4-r-phone',
                    'title' => 'Verify phone_click firing on Contact and service CTAs',
                    'finding_id' => 'ga4-f-phone-review',
                    'status' => 'accepted',
                ],
            ],
            'tasks' => [
                [
                    'id' => 'ga4-t-interruption',
                    'title' => 'Investigate Lead Form 36h silence (tag / GTM / form endpoint)',
                    'status' => 'completed',
                    'owner' => 'Ops',
                    'due' => '6 Aug',
                ],
                [
                    'id' => 'ga4-t-whatsapp',
                    'title' => 'Configure WhatsApp business-action mapping',
                    'status' => 'open',
                    'owner' => 'Ops',
                    'due' => 'Next week',
                ],
                [
                    'id' => 'ga4-t-utm',
                    'title' => 'Align Meta + Google Ads campaign UTM parameters',
                    'status' => 'open',
                    'owner' => 'Media buyer',
                    'due' => 'Next week',
                ],
                [
                    'id' => 'ga4-t-phone',
                    'title' => 'QA phone_click on /contact and /implant CTAs',
                    'status' => 'open',
                    'owner' => 'Website developer',
                    'due' => '14 Aug',
                ],
            ],
            'outcomes' => [
                [
                    'task' => 'Investigate Lead Form 36h silence (tag / GTM / form endpoint)',
                    'state' => 'Improvement observed',
                    'note' => 'Later window resumed generate_lead volume after form-endpoint fix; interruption Finding retained as history.',
                ],
                [
                    'task' => 'Configure WhatsApp business-action mapping',
                    'state' => 'Insufficient evidence',
                    'note' => 'Event still Not mapped — cannot treat WhatsApp demand as measured business actions.',
                ],
                [
                    'task' => 'Align Meta + Google Ads campaign UTM parameters',
                    'state' => 'Still observed',
                    'note' => 'utm_campaign unavailable remains elevated vs the 6% prior baseline.',
                ],
            ],
            'finding_detail' => [
                'ga4-f-lead-interruption' => [
                    'what' => 'generate_lead produced no usable signal for ~36 hours while sessions continued.',
                    'why' => 'Lead volume interpretation and Ads CPA reads are unreliable during silent windows.',
                    'scope' => 'Web stream · /implant · /contact',
                    'evidence' => 'GA4 event timeline · Demo daily fixtures · Aug 4–5 gap',
                    'next' => 'Keep mapping healthy; monitor recurrence; record outcome when signal resumes.',
                    'outcome' => 'Improvement observed after form-endpoint remediation Task.',
                ],
                'ga4-f-whatsapp-unmapped' => [
                    'what' => 'whatsapp_click fires across service pages but WhatsApp business action is Not mapped.',
                    'why' => 'Operators cannot count WhatsApp demand as business outcomes (missing ≠ zero).',
                    'scope' => 'Site-wide CTA · Website Settings measurement',
                    'evidence' => 'GA4 events · Website CTA inventory',
                    'next' => 'Map whatsapp_click to WhatsApp in Website / GA4 measurement settings.',
                ],
                'ga4-f-utm-hygiene' => [
                    'what' => 'Sessions with unavailable utm_campaign rose from 6% to 18%.',
                    'why' => 'Paid and partner attribution becomes harder to trust.',
                    'scope' => 'Paid Social · Paid Search · partner referrals',
                    'evidence' => 'GA4 campaign dimension · acquisition fixtures',
                    'next' => 'Align campaign UTM parameters on Meta and Google Ads destinations (internal review only).',
                ],
                'ga4-f-self-referral' => [
                    'what' => 'atlasdental.example appears as a material referral source.',
                    'why' => 'Self-referrals can inflate referral and under-count Direct/Organic paths.',
                    'scope' => 'Referral channel',
                    'evidence' => 'GA4 source/medium · referral table',
                    'next' => 'Review referral exclusion list / cross-domain configuration (Demo · no live Admin write).',
                ],
                'ga4-f-phone-review' => [
                    'what' => 'phone_click is mapped but volume looks sparse relative to phone CTA presence.',
                    'why' => 'Phone demand may be under-instrumented — do not treat as zero interest.',
                    'scope' => '/contact · /implant · header CTA',
                    'evidence' => 'GA4 phone_click · Website CTA inventory',
                    'next' => 'QA click handlers; keep state as Review until volume is trustworthy.',
                ],
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
                'title' => 'Lead Form interruption investigation',
                'state' => 'Improvement observed',
                'note' => 'Signal resumed after form-endpoint fix',
            ],
            [
                'title' => 'WhatsApp business-action mapping',
                'state' => 'Insufficient evidence',
                'note' => 'Still Not mapped',
            ],
            [
                'title' => 'UTM campaign hygiene',
                'state' => 'Still observed',
                'note' => 'Unavailable share 18%',
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
                'title' => 'Map WhatsApp business action',
                'metric' => 'Event present · action Not mapped',
                'cta' => 'Open measurement',
                'tab' => 'measurement',
            ],
            [
                'priority' => 'High',
                'title' => 'Repair campaign UTM coverage',
                'metric' => 'Unavailable 6% → 18%',
                'cta' => 'Open acquisition',
                'tab' => 'acquisition',
            ],
            [
                'priority' => 'Medium',
                'title' => 'Review self-referral exclusion',
                'metric' => 'atlasdental.example / referral',
                'cta' => 'Open measurement',
                'tab' => 'measurement',
            ],
            [
                'priority' => 'Medium',
                'title' => 'QA phone_click instrumentation',
                'metric' => 'Mapped · Review state',
                'cta' => 'Open measurement',
                'tab' => 'measurement',
            ],
            [
                'priority' => 'Explore',
                'title' => 'Enrich Implant landing journey',
                'metric' => 'Top landing · Website LCP Finding',
                'cta' => 'Open behavior',
                'tab' => 'behavior',
            ],
        ];
    }

    /**
     * Deterministic daily weight for property-level metrics (normalized via aggregate).
     *
     * @return array{users: float, sessions: float, lead_form: float, phone: float, whatsapp: float, form_start: float}
     */
    public static function rawDayWeight(string $date): array
    {
        if (! DemoPeriod::inFixtureAnchorContext()) {
            return DemoPeriod::usingFixtureAnchor(fn (): array => self::rawDayWeight($date));
        }

        $hash = crc32($date.'|ga4-atlas|demo');
        $unit = ($hash % 10000) / 10000;
        $dow = (int) Carbon::parse($date, DemoPeriod::TIMEZONE)->dayOfWeekIso;
        $weekend = $dow >= 6 ? 0.86 : 1.0;
        $wave = 0.80 + 0.40 * abs(sin(($hash % 360) * M_PI / 180));

        // Lead Form silence story: Aug 4–5 sparse weights (interruption candidate).
        $leadFactor = 1.0;
        if (in_array($date, ['2026-08-04', '2026-08-05'], true)) {
            $leadFactor = $date === '2026-08-04' ? 0.08 : 0.12;
        }

        return [
            'users' => max(0.15, $unit * $weekend * $wave),
            'sessions' => max(0.18, (0.45 + $unit) * $weekend * $wave),
            'lead_form' => max(0.02, (0.30 + $unit * 0.95) * $weekend * $wave * $leadFactor),
            'phone' => max(0.08, (0.25 + $unit * 0.8) * $weekend * $wave),
            'whatsapp' => max(0.10, (0.35 + $unit * 0.85) * $weekend * $wave),
            'form_start' => max(0.20, (0.50 + $unit) * $weekend * $wave),
        ];
    }

    /**
     * Aggregate property metrics for an inclusive date range, scaled to last_28 baselines.
     *
     * @return array{
     *     users: int,
     *     sessions: int,
     *     lead_form: int,
     *     phone: int,
     *     whatsapp: int,
     *     form_start: int,
     *     mapped_actions: int
     * }
     */
    public static function aggregateProperty(string $start, string $end): array
    {
        if (! DemoPeriod::inFixtureAnchorContext()) {
            return DemoPeriod::usingFixtureAnchor(fn (): array => self::aggregateProperty($start, $end));
        }

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

        $users = $scale($baselineWeights['users'], $rangeWeights['users'], self::BASELINE_USERS);
        $sessions = $scale($baselineWeights['sessions'], $rangeWeights['sessions'], self::BASELINE_SESSIONS);
        $leadForm = $scale($baselineWeights['lead_form'], $rangeWeights['lead_form'], self::BASELINE_LEAD_FORM);
        $phone = $scale($baselineWeights['phone'], $rangeWeights['phone'], self::BASELINE_PHONE);
        $whatsapp = $scale($baselineWeights['whatsapp'], $rangeWeights['whatsapp'], self::BASELINE_WHATSAPP);
        $formStart = $scale($baselineWeights['form_start'], $rangeWeights['form_start'], self::BASELINE_FORM_START);

        // Mapped actions = Lead Form + Phone only (WhatsApp / Appointment Not mapped).
        $mapped = $leadForm + $phone;
        // Keep last_28 close to the published baseline when ranges match.
        if ($start === $baselineStart && $end === $baselineEnd) {
            $mapped = self::BASELINE_MAPPED_ACTIONS;
            $leadForm = self::BASELINE_LEAD_FORM;
            $phone = self::BASELINE_PHONE;
            $users = self::BASELINE_USERS;
            $sessions = self::BASELINE_SESSIONS;
            $whatsapp = self::BASELINE_WHATSAPP;
            $formStart = self::BASELINE_FORM_START;
        }

        return [
            'users' => $users,
            'sessions' => $sessions,
            'lead_form' => $leadForm,
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'form_start' => $formStart,
            'mapped_actions' => $mapped,
        ];
    }

    /**
     * @return array{users: float, sessions: float, lead_form: float, phone: float, whatsapp: float, form_start: float}
     */
    private static function sumWeights(string $start, string $end): array
    {
        $sum = [
            'users' => 0.0,
            'sessions' => 0.0,
            'lead_form' => 0.0,
            'phone' => 0.0,
            'whatsapp' => 0.0,
            'form_start' => 0.0,
        ];
        foreach (self::daysInRange($start, $end) as $date) {
            $w = self::rawDayWeight($date);
            foreach ($sum as $key => $_) {
                $sum[$key] += $w[$key];
            }
        }

        return $sum;
    }

    /**
     * Scaled single-day absolute metrics (for trend series).
     *
     * @return array{users: int, sessions: int, mapped_actions: int, lead_form: int, phone: int}
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

        $lead = $scale($w['lead_form'], $baselineWeights['lead_form'], self::BASELINE_LEAD_FORM);
        $phone = $scale($w['phone'], $baselineWeights['phone'], self::BASELINE_PHONE);

        return [
            'users' => $scale($w['users'], $baselineWeights['users'], self::BASELINE_USERS),
            'sessions' => $scale($w['sessions'], $baselineWeights['sessions'], self::BASELINE_SESSIONS),
            'lead_form' => $lead,
            'phone' => $phone,
            'mapped_actions' => $lead + $phone,
        ];
    }

    /**
     * @return list<string>
     */
    public static function daysInRange(string $start, string $end): array
    {
        if (! DemoPeriod::inFixtureAnchorContext()) {
            return DemoPeriod::usingFixtureAnchor(fn (): array => self::daysInRange($start, $end));
        }

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

    private static function pctDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private static function formatDelta(float $delta): string
    {
        $prefix = $delta > 0 ? '+' : '';

        return $prefix.number_format($delta, 1).'%';
    }
}
