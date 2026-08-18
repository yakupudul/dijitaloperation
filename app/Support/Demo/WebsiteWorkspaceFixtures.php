<?php

namespace App\Support\Demo;

/**
 * Demo-only Website operating workspace fixtures (Atlas Dental story).
 *
 * Intentionally not production collectors. Labels and provenance must stay honest.
 */
final class WebsiteWorkspaceFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function workspace(string $preset = 'last_28'): array
    {
        $f = DemoCatalog::periodFactors($preset);
        $base = DemoCatalog::websiteOverview($preset);

        return array_merge($base, [
            'identity' => self::identity(),
            'source_freshness' => self::sourceFreshness(),
            'glance' => self::glance($f),
            'needs_attention' => self::needsAttention(),
            'opportunities' => self::opportunities($f),
            'inventory_snapshot' => self::inventorySnapshot(),
            'search_snapshot' => self::searchSnapshot($f),
            'conversion_snapshot' => self::conversionSnapshot($f),
            'recent_outcomes' => self::recentOutcomes(),
            'health' => self::health(),
            'visibility' => self::visibility($f),
            'content_workspace' => self::contentWorkspace($f),
            'performance_workspace' => self::performanceWorkspace($f, $base, $preset),
            'connections' => self::connections(),
            'activity' => self::activity(),
            'settings' => self::settings(),
            'ai_guidance' => self::aiGuidance(),
            'demo_boundary' => 'Demo Mode · product vision fixtures — not live provider collection',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'title' => 'Atlas Dental Website',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'domain' => 'atlasdental.example',
            'primary_url' => 'https://atlasdental.example',
            'cms' => 'WordPress',
            'languages' => 'Turkish + English',
            'market' => 'Türkiye',
            'status' => 'Active',
            'status_note' => 'Production website',
            'last_refresh' => '2 hours ago',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sourceFreshness(): array
    {
        return [
            ['source' => 'WordPress', 'state' => 'current', 'label' => 'Updated 4h ago', 'tab' => 'setup'],
            ['source' => 'Search Console', 'state' => 'current', 'label' => 'Updated 2h ago', 'tab' => 'setup'],
            ['source' => 'GA4', 'state' => 'current', 'label' => 'Updated 2h ago', 'tab' => 'setup'],
            ['source' => 'Diagnosis', 'state' => 'stale', 'label' => '1 day ago', 'tab' => 'health'],
            ['source' => 'SEO Intelligence', 'state' => 'stale', 'label' => '4 days ago', 'tab' => 'visibility'],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function glance(array $f): array
    {
        return [
            'open_findings' => 12,
            'high_findings' => 3,
            'active_tasks' => 5,
            'overdue_tasks' => 1,
            'search_visibility' => [
                'value' => number_format((int) round(1420 * ($f['results_factor'] ?? 1))).' organic queries observed',
                'secondary' => 'Search Console · measured · '.$f['label'],
            ],
            'site_inventory' => [
                'value' => '184 known URLs',
                'secondary' => 'WordPress + sitemap · partial coverage',
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
                'severity' => 'High',
                'category' => 'Technical',
                'what' => '27 service pages have no self-referencing canonical.',
                'where' => 'Service pages · WordPress treatment template',
                'why' => 'Index clarity suffers when templates omit canonicals across the service estate.',
                'actionability' => 'Developer required',
                'recommended' => 'Add self-referencing canonical to the shared treatment template.',
                'finding_id' => 'wf-canonical-template',
                'affected_scope' => '27 pages',
            ],
            [
                'severity' => 'High',
                'category' => 'Performance',
                'what' => 'Mobile lab LCP is 4.1s on /implant.',
                'where' => '/implant · primary paid + organic landing',
                'why' => 'Paid and organic traffic land on a slow mobile page.',
                'actionability' => 'Developer required',
                'recommended' => 'Compress hero media and defer non-critical third-party scripts.',
                'finding_id' => 'wf-lcp-implant',
                'affected_scope' => '1 page',
            ],
            [
                'severity' => 'High',
                'category' => 'Conversion',
                'what' => 'WhatsApp CTAs exist on 23 pages without a mapped business action.',
                'where' => 'Site-wide CTA · GA4 measurement',
                'why' => 'Operators cannot tell whether WhatsApp demand converts.',
                'actionability' => 'Agency can fix',
                'recommended' => 'Map whatsapp_click to the WhatsApp business action in Website Settings.',
                'finding_id' => 'wf-measurement-gap',
                'affected_scope' => '23 pages',
            ],
            [
                'severity' => 'Medium',
                'category' => 'Infrastructure',
                'what' => 'Hosting renewal due in 34 days.',
                'where' => 'Website Infrastructure · DemoHost',
                'why' => 'Renewal continuity risk — not an outage yet.',
                'actionability' => 'Client input required',
                'recommended' => 'Review hosting renewal on Website → Infrastructure.',
                'finding_id' => null,
                'affected_scope' => 'Hosting',
                'tab' => 'infrastructure',
            ],
            [
                'severity' => 'Medium',
                'category' => 'Content',
                'what' => 'Implant recovery intent has weak Website coverage.',
                'where' => 'Offering · Dental implants',
                'why' => 'Brand sells implants; related queries are observed; no dedicated supporting content.',
                'actionability' => 'Agency can fix',
                'recommended' => 'Review as a content opportunity (supporting article or service section).',
                'finding_id' => null,
                'affected_scope' => 'Topic gap',
            ],
            [
                'severity' => 'Medium',
                'category' => 'Local',
                'what' => 'Website phone number format differs from related GBP listing.',
                'where' => 'Contact page ↔ Google Business Profile',
                'why' => 'Entity consistency supports local discoverability.',
                'actionability' => 'Client decision required',
                'recommended' => 'Confirm canonical phone with clinic ops, then align Website and GBP.',
                'finding_id' => 'wf-local-phone',
                'affected_scope' => 'Cross-asset',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public static function opportunities(array $f): array
    {
        return [
            [
                'priority' => 'High priority',
                'title' => 'Striking-distance query · implant ankara',
                'why' => 'Avg position 8.2 with material impressions — MoxDOP heuristic.',
                'source' => 'Search Console · measured',
                'action' => 'Inspect Visibility',
                'tab' => 'visibility',
            ],
            [
                'priority' => 'High priority',
                'title' => 'High impressions / weak CTR · diş implantı fiyat',
                'why' => 'Position ~18 with weak CTR versus branded terms.',
                'source' => 'Search Console · measured',
                'action' => 'Inspect Visibility',
                'tab' => 'visibility',
            ],
            [
                'priority' => 'Medium priority',
                'title' => 'Content gap · Implant recovery expectations',
                'why' => 'Brand offering + observed query cluster + weak page coverage.',
                'source' => 'Brand Context · GSC · Content inventory',
                'action' => 'Inspect Content',
                'tab' => 'content',
            ],
            [
                'priority' => 'Medium priority',
                'title' => 'Potential content decay · /dental-implants/',
                'why' => 'Clicks −31% / impressions −18% vs prior window; last modified 14 months ago.',
                'source' => 'Search Console · WordPress',
                'action' => 'Inspect Content',
                'tab' => 'content',
            ],
            [
                'priority' => 'Explore',
                'title' => 'Rising interest · All-on-4 recovery time (UK)',
                'why' => 'Matches Implant offering and UK target market; Website coverage weak.',
                'source' => 'Trend Intelligence · Demo fixture',
                'action' => 'Inspect Content',
                'tab' => 'content',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function inventorySnapshot(): array
    {
        return [
            'pages' => 42,
            'posts' => 86,
            'custom_types' => 4,
            'media' => 712,
            'sitemap_urls' => 154,
            'label' => 'REST-visible WordPress content · Sitemap',
            'reconciliation' => [
                'wordpress_published' => 184,
                'sitemap_urls' => 161,
                'crawlable_observed' => 158,
                'search_performance_pages' => 96,
                'note' => '23 CMS items are not represented in the current sitemap evidence.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function searchSnapshot(array $f): array
    {
        return [
            'clicks' => (int) round(8420 * ($f['results_factor'] ?? 1)),
            'impressions' => (int) round(186000 * ($f['results_factor'] ?? 1)),
            'ctr' => 4.5,
            'avg_position' => 12.4,
            'window' => $f['label'],
            'gsc_label' => 'Search Console · measured',
            'dataforseo_opportunities' => 18,
            'dataforseo_label' => 'DataForSEO · estimated',
            'top_landing' => ['/implant', '/post-bariatric', '/'],
            'striking_distance' => 6,
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function conversionSnapshot(array $f): array
    {
        return [
            'configured' => true,
            'mapped' => [
                ['action' => 'Form submission', 'event' => 'generate_lead', 'count' => (int) round(148 * ($f['results_factor'] ?? 1))],
                ['action' => 'Phone call', 'event' => 'phone_click', 'count' => (int) round(18 * ($f['results_factor'] ?? 1))],
            ],
            'gaps' => [
                'WhatsApp click CTAs detected on 23 pages, but WhatsApp business action is not mapped in MoxDOP.',
            ],
            'configure_tab' => 'setup',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentOutcomes(): array
    {
        return [
            [
                'title' => 'Title canonical issue on blog template',
                'task' => 'Task completed 5 days ago',
                'follow_up' => 'Later check: no longer observed',
                'state' => 'Improvement observed',
            ],
            [
                'title' => 'Appointment-form tracking verification',
                'task' => 'Task completed',
                'follow_up' => 'Follow-up: insufficient evidence',
                'state' => 'Insufficient evidence',
            ],
            [
                'title' => 'GBP relevance audit (related asset)',
                'task' => 'Task completed 28 days ago',
                'follow_up' => 'Associated improvement observed — not claiming causality',
                'state' => 'Improvement observed',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function health(): array
    {
        return [
            'summary' => [
                'checks_evaluated' => 34,
                'findings_open' => 12,
                'high_severity' => 3,
                'checks_unavailable' => 5,
            ],
            'groups' => [
                'technical' => 'Technical',
                'crawl' => 'Crawl & Indexability',
                'onpage' => 'On-page & Head',
                'structured' => 'Structured Data',
                'performance' => 'Performance',
                'security' => 'Security',
                'wordpress' => 'WordPress / CMS',
                'availability' => 'Availability',
            ],
            'findings' => [
                [
                    'id' => 'wf-canonical-template',
                    'group' => 'onpage',
                    'severity' => 'high',
                    'title' => '27 service pages have no self-referencing canonical',
                    'where' => 'WordPress treatment template',
                    'why' => 'Shared template omits canonical across the service estate.',
                    'evidence' => 'Diagnosis crawl · 27 /treatments/* URLs without rel=canonical',
                    'affected_scope' => '27 pages',
                    'actionability' => 'Developer required',
                    'suggested_owner' => 'Developer',
                    'recommended' => 'Add self-referencing canonical to the shared treatment template.',
                    'verification' => 'Re-run On-page & Head check; expect canonical present on sample URLs.',
                    'success_signal' => 'Canonical present on sampled treatment URLs',
                    'failure_signal' => 'Canonical still missing after deploy',
                    'related_recommendation' => 'r-fix-lcp',
                    'related_task' => null,
                ],
                [
                    'id' => 'wf-lcp-implant',
                    'group' => 'performance',
                    'severity' => 'high',
                    'title' => 'Mobile lab LCP 4.1s on /implant',
                    'where' => '/implant',
                    'why' => 'Paid and organic landings share this bottleneck.',
                    'evidence' => 'Lab LCP 4.1s · Field LCP 3.6s (mobile) · hero 1.8MB',
                    'affected_scope' => '1 page',
                    'actionability' => 'Developer required',
                    'suggested_owner' => 'Developer',
                    'recommended' => 'Compress hero media; defer chat widget.',
                    'verification' => 'Re-run lab check on /implant after deploy.',
                    'success_signal' => 'Mobile lab LCP ≤ 2.5s',
                    'failure_signal' => 'LCP remains > 3.5s',
                    'related_recommendation' => 'r-fix-lcp',
                    'related_task' => 't-lcp',
                ],
                [
                    'id' => 'wf-broken-links',
                    'group' => 'crawl',
                    'severity' => 'medium',
                    'title' => '12 broken internal links across service pages',
                    'where' => 'Service pages',
                    'why' => 'Crawl waste and thin UX on key paths.',
                    'evidence' => 'Crawl · Public + Detected · 12 404 targets',
                    'affected_scope' => '12 links',
                    'actionability' => 'Agency can fix',
                    'suggested_owner' => 'SEO/content',
                    'recommended' => 'Replace or remove broken internal targets.',
                    'verification' => 'Re-crawl affected pages.',
                    'success_signal' => 'Broken internal link count = 0 on recheck',
                    'failure_signal' => 'Broken links remain',
                    'related_recommendation' => null,
                    'related_task' => null,
                ],
                [
                    'id' => 'wf-missing-canonical-pages',
                    'group' => 'onpage',
                    'severity' => 'medium',
                    'title' => '7 pages report no canonical tags',
                    'where' => 'Mixed templates',
                    'why' => 'Duplicate-risk pages may compete with primary URLs.',
                    'evidence' => 'Diagnosis · 7 pages without canonical',
                    'affected_scope' => '7 pages',
                    'actionability' => 'Developer required',
                    'suggested_owner' => 'Developer',
                    'recommended' => 'Ensure every indexable template emits a canonical.',
                    'verification' => 'Recheck document head on listed URLs.',
                    'success_signal' => 'Canonical present',
                    'failure_signal' => 'Still missing',
                    'related_recommendation' => null,
                    'related_task' => null,
                ],
                [
                    'id' => 'wf-schema',
                    'group' => 'structured',
                    'severity' => 'low',
                    'title' => '3 key pages missing LocalBusiness / FAQ schema',
                    'where' => 'Homepage, /contact, /implant',
                    'why' => 'Structured data opportunities for local/entity clarity.',
                    'evidence' => 'Diagnosis · no LocalBusiness/FAQ JSON-LD on sample',
                    'affected_scope' => '3 pages',
                    'actionability' => 'Developer required',
                    'suggested_owner' => 'Developer',
                    'recommended' => 'Add appropriate Organization/LocalBusiness and FAQ where content exists.',
                    'verification' => 'Structured data parse on recheck',
                    'success_signal' => 'Valid LocalBusiness on /contact',
                    'failure_signal' => 'Still absent or invalid',
                    'related_recommendation' => null,
                    'related_task' => null,
                ],
                [
                    'id' => 'wf-security-headers',
                    'group' => 'security',
                    'severity' => 'low',
                    'title' => 'Missing Content-Security-Policy header',
                    'where' => 'Site-wide response headers',
                    'why' => 'Hygiene signal — not a confirmed vulnerability.',
                    'evidence' => 'Response header scan · CSP absent',
                    'affected_scope' => 'Website-wide',
                    'actionability' => 'Hosting/provider required',
                    'suggested_owner' => 'Hosting provider',
                    'recommended' => 'Escalate CSP configuration with hosting; monitor only until owner confirms.',
                    'verification' => 'Header recheck after hosting change',
                    'success_signal' => 'CSP present',
                    'failure_signal' => 'Still absent',
                    'related_recommendation' => null,
                    'related_task' => null,
                ],
                [
                    'id' => 'wf-wp-updates',
                    'group' => 'wordpress',
                    'severity' => 'medium',
                    'title' => '4 plugins report updates available',
                    'where' => 'WordPress · plugins',
                    'why' => 'Update available ≠ known vulnerability. Review with developer.',
                    'evidence' => 'WordPress REST · update flags (demo)',
                    'affected_scope' => '4 plugins',
                    'actionability' => 'Developer required',
                    'suggested_owner' => 'Developer',
                    'recommended' => 'Review changelogs; schedule updates outside MoxDOP (read-only).',
                    'verification' => 'WordPress inventory refresh',
                    'success_signal' => 'No pending updates on reviewed plugins',
                    'failure_signal' => 'Updates still pending',
                    'related_recommendation' => null,
                    'related_task' => null,
                ],
                [
                    'id' => 'wf-local-phone',
                    'group' => 'technical',
                    'severity' => 'medium',
                    'title' => 'Website phone format differs from related GBP',
                    'where' => '/contact ↔ GBP',
                    'why' => 'Entity consistency for local discoverability.',
                    'evidence' => 'Cross-asset check · phone mismatch',
                    'affected_scope' => 'Cross-asset',
                    'actionability' => 'Client decision required',
                    'suggested_owner' => 'Client',
                    'recommended' => 'Confirm canonical phone, then align both surfaces.',
                    'verification' => 'Re-run Website ↔ GBP consistency check',
                    'success_signal' => 'Phone matches',
                    'failure_signal' => 'Mismatch remains',
                    'related_recommendation' => null,
                    'related_task' => null,
                ],
            ],
            'wordpress' => [
                'version' => '6.5.3',
                'theme' => 'Atlas Dental Theme 2.4.1',
                'theme_update' => false,
                'plugin_count' => 28,
                'plugin_updates' => 4,
                'rest_state' => 'Reachable',
                'content_types' => ['page', 'post', 'treatment', 'doctor', 'faq', 'branch'],
                'note' => 'Read-only. MoxDOP does not update plugins or publish content.',
            ],
            'availability' => [
                'configured' => false,
                'demo_timeline' => [
                    ['date' => '11 Aug 2026', 'window' => '02:14 – 02:19', 'state' => 'Unavailable', 'duration' => '5 min'],
                    ['date' => '8 Aug 2026', 'window' => '03:02 – 03:18', 'state' => 'HTTP 503', 'duration' => '16 min'],
                ],
                'demo_note' => 'Demo Mode timeline illustrating intended UX. Availability monitoring is not configured in production.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function visibility(array $f): array
    {
        $rf = $f['results_factor'] ?? 1.0;

        return [
            'lenses' => ['organic' => 'Organic Search', 'local' => 'Local', 'ai' => 'AI Visibility'],
            'organic' => [
                'window' => $f['label'],
                'source' => 'Search Console · measured',
                'kpis' => [
                    'clicks' => (int) round(8420 * $rf),
                    'impressions' => (int) round(186000 * $rf),
                    'ctr' => 4.5,
                    'avg_position' => 12.4,
                ],
                'groups' => [
                    'growing' => [
                        ['query' => 'atlas dental ankara', 'clicks' => (int) round(380 * $rf), 'delta' => '+18%', 'page' => '/'],
                        ['query' => 'implant ankara', 'clicks' => (int) round(920 * $rf), 'delta' => '+9%', 'page' => '/implant'],
                    ],
                    'declining' => [
                        ['query' => 'diş implantı fiyat', 'clicks' => (int) round(410 * $rf), 'delta' => '−22%', 'page' => '/implant'],
                        ['query' => 'post bariatric diş', 'clicks' => (int) round(210 * $rf), 'delta' => '−14%', 'page' => '/post-bariatric'],
                    ],
                    'striking_distance' => [
                        ['query' => 'implant ankara', 'position' => 8.2, 'impressions' => (int) round(12400 * $rf), 'note' => 'MoxDOP heuristic'],
                        ['query' => 'diş kliniği çankaya', 'position' => 9.1, 'impressions' => (int) round(6200 * $rf), 'note' => 'MoxDOP heuristic'],
                    ],
                    'high_impression_low_ctr' => [
                        ['query' => 'diş implantı fiyat', 'impressions' => (int) round(28600 * $rf), 'ctr' => 1.4, 'position' => 18.0],
                    ],
                    'new_visibility' => [
                        ['query' => 'all on 4 ankara', 'impressions' => (int) round(840 * $rf), 'page' => '/implant'],
                    ],
                    'lost_visibility' => [
                        ['query' => 'zirkonyum kaplama ankara', 'prior_clicks' => 64, 'page' => '/treatments/zirconia'],
                    ],
                ],
                'query_pages' => [
                    [
                        'query' => 'implant turkey',
                        'primary_page' => '/dental-implants/',
                        'impressions' => (int) round(4620 * $rf),
                        'clicks' => (int) round(186 * $rf),
                        'avg_position' => 8.4,
                        'competing_pages' => 2,
                        'overlap_label' => 'Potential query overlap',
                        'confidence' => 'Medium',
                    ],
                    [
                        'query' => 'implant ankara',
                        'primary_page' => '/implant',
                        'impressions' => (int) round(12400 * $rf),
                        'clicks' => (int) round(920 * $rf),
                        'avg_position' => 8.2,
                        'competing_pages' => 1,
                        'overlap_label' => null,
                        'confidence' => null,
                    ],
                ],
                'dataforseo' => [
                    'ranked_keywords' => 428,
                    'keywords_for_site' => 96,
                    'opportunities' => 18,
                    'label' => 'DataForSEO · estimated',
                    'guard' => 'Paid refresh requires confirmation · never on page render',
                ],
            ],
            'local' => [
                'active' => true,
                'reason' => 'Local business Brand · related GBP Digital Asset exists',
                'signals' => [
                    ['label' => 'Location information on Website', 'state' => 'Present'],
                    ['label' => 'Service + location relationships', 'state' => 'Partial'],
                    ['label' => 'Contact / business details', 'state' => 'Needs review · phone mismatch'],
                    ['label' => 'LocalBusiness structured data', 'state' => 'Missing on key pages'],
                    ['label' => 'Local-intent GSC queries', 'state' => 'Observed'],
                ],
                'matrix' => [
                    'headers' => ['Service', 'Ankara', 'Çankaya', 'Existing coverage'],
                    'rows' => [
                        ['Implant', 'Yes', 'Partial', '2 pages'],
                        ['Orthodontics', 'Yes', 'Missing', '1 page'],
                        ['Smile Design', 'Partial', 'Missing', '1 page'],
                    ],
                    'note' => 'Controllable Website/Brand signals — not a local ranking guarantee.',
                ],
                'gbp' => [
                    'asset_name' => 'Atlas Dental Ankara',
                    'route' => 'operator.gbp',
                    'note' => 'Related Brand Digital Asset — not a Website connection.',
                ],
            ],
            'ai' => [
                'readiness' => [
                    ['condition' => 'Crawl accessibility', 'state' => 'OK'],
                    ['condition' => 'Clear Brand / entity identification', 'state' => 'Partial'],
                    ['condition' => 'Coherent service information', 'state' => 'Strong'],
                    ['condition' => 'Structured business information', 'state' => 'Weak'],
                    ['condition' => 'Citation-friendly depth on priority offerings', 'state' => 'Partial'],
                ],
                'readiness_note' => 'AI readiness = site conditions. Not an AI Readiness Score.',
                'observed' => [
                    'measured' => false,
                    'demo_rows' => [
                        [
                            'platform' => 'ChatGPT',
                            'query' => 'best dental implant clinic ankara',
                            'mentioned' => true,
                            'recommended' => false,
                            'cited' => false,
                            'when' => 'Demo sample · not production observation',
                        ],
                    ],
                    'sample_note' => 'AI visibility has not been measured in production. Demo rows illustrate intended UX only.',
                ],
                'referrals' => [
                    'label' => 'AI-assisted referral traffic',
                    'sessions' => (int) round(42 * $rf),
                    'source' => 'GA4 · measured · chatgpt.com / referral',
                    'note' => 'Referral traffic ≠ share of AI mentions.',
                ],
                'generative_search' => [
                    'available' => false,
                    'message' => 'Generative search reporting is not collected in the current connector.',
                ],
            ],
            'competitors' => [
                'capability' => 'Bounded Discovery only · uncontrolled competitor crawling not implemented',
                'known' => ['Ankara Implant Center', 'Smile Ankara Clinic'],
                'note' => 'Do not claim “Competitor X is better” without Evidence.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function contentWorkspace(array $f): array
    {
        $rf = $f['results_factor'] ?? 1.0;

        return [
            'inventory' => [
                ['label' => 'Pages', 'count' => 42, 'source' => 'WordPress · REST-visible'],
                ['label' => 'Posts', 'count' => 86, 'source' => 'WordPress · REST-visible'],
                ['label' => 'Treatments', 'count' => 31, 'source' => 'CPT · treatment'],
                ['label' => 'Doctors', 'count' => 12, 'source' => 'CPT · doctor'],
                ['label' => 'FAQ', 'count' => 94, 'source' => 'CPT · faq'],
                ['label' => 'Branches', 'count' => 6, 'source' => 'CPT · branch'],
                ['label' => 'Media', 'count' => 712, 'source' => 'WordPress'],
                ['label' => 'Sitemap URLs', 'count' => 154, 'source' => 'Sitemap'],
            ],
            'roles' => ['Home', 'Service / Product', 'Location', 'Service + Location', 'Topic Hub', 'Blog / Article', 'FAQ / Q&A', 'Team / Expert', 'About / Brand', 'Conversion / Contact', 'Other / Unknown'],
            'directory' => [
                [
                    'id' => 'c-implant',
                    'title' => 'Dental Implants in Ankara',
                    'url' => '/implant',
                    'role' => 'Service / Product',
                    'cms_type' => 'page',
                    'topic' => 'Dental implants',
                    'organic' => (int) round(2100 * $rf).' clicks',
                    'traffic' => (int) round(6200 * $rf).' sessions',
                    'events' => (int) round(88 * $rf).' forms',
                    'updated' => '12 Jun 2025',
                    'state' => 'Needs refresh',
                    'classification' => 'Deterministic',
                    'language' => 'tr + en',
                    'h1' => 'Dental Implants in Ankara',
                    'word_count' => 1480,
                    'schema' => 'None',
                    'findings' => ['Mobile LCP 4.1s', 'Thin FAQ block'],
                    'opportunities' => ['Expand recovery section', 'Improve CTR for fiyat intent'],
                ],
                [
                    'id' => 'c-pb',
                    'title' => 'Post-Bariatric Dentistry',
                    'url' => '/post-bariatric',
                    'role' => 'Service / Product',
                    'cms_type' => 'treatment',
                    'topic' => 'Post-bariatric dentistry',
                    'organic' => (int) round(860 * $rf).' clicks',
                    'traffic' => (int) round(4100 * $rf).' sessions',
                    'events' => (int) round(54 * $rf).' forms',
                    'updated' => '02 May 2026',
                    'state' => 'Strong',
                    'classification' => 'Deterministic',
                    'language' => 'en',
                    'h1' => 'Post-Bariatric Dental Care',
                    'word_count' => 980,
                    'schema' => 'MedicalProcedure (partial)',
                    'findings' => [],
                    'opportunities' => ['Add recovery timeline H2'],
                ],
                [
                    'id' => 'c-care',
                    'title' => 'Implant care guide',
                    'url' => '/blog/implant-bakimi',
                    'role' => 'Blog / Article',
                    'cms_type' => 'post',
                    'topic' => 'Dental implants · aftercare',
                    'organic' => 'Not observed',
                    'traffic' => 'Not collected',
                    'events' => '—',
                    'updated' => '28 Jul 2026',
                    'state' => 'Draft · opportunity',
                    'classification' => 'Deterministic',
                    'language' => 'tr',
                    'h1' => 'Implant bakımı',
                    'word_count' => 640,
                    'schema' => 'None',
                    'findings' => ['Not published'],
                    'opportunities' => ['Publish to capture informational intent'],
                ],
                [
                    'id' => 'c-team',
                    'title' => 'Our specialists',
                    'url' => '/team',
                    'role' => 'Team / Expert',
                    'cms_type' => 'page',
                    'topic' => 'Authority',
                    'organic' => (int) round(410 * $rf).' clicks',
                    'traffic' => (int) round(980 * $rf).' sessions',
                    'events' => '—',
                    'updated' => '18 Mar 2026',
                    'state' => 'Thin',
                    'classification' => 'AI-classified',
                    'language' => 'tr + en',
                    'h1' => 'Our specialists',
                    'word_count' => 720,
                    'schema' => 'None',
                    'findings' => ['Short bios'],
                    'opportunities' => ['Add credentials schema'],
                ],
                [
                    'id' => 'c-contact',
                    'title' => 'Contact',
                    'url' => '/contact',
                    'role' => 'Conversion / Contact',
                    'cms_type' => 'page',
                    'topic' => 'Conversion',
                    'organic' => (int) round(520 * $rf).' clicks',
                    'traffic' => (int) round(2100 * $rf).' sessions',
                    'events' => (int) round(31 * $rf).' forms',
                    'updated' => '04 Jan 2026',
                    'state' => 'Phone mismatch',
                    'classification' => 'Deterministic',
                    'language' => 'tr + en',
                    'h1' => 'Contact Atlas Dental',
                    'word_count' => 420,
                    'schema' => 'None',
                    'findings' => ['Phone ≠ GBP'],
                    'opportunities' => ['Add LocalBusiness schema'],
                ],
            ],
            'coverage' => [
                'offering' => 'Dental implants',
                'rows' => [
                    ['need' => 'Core service', 'state' => 'Covered', 'why' => '/implant service page exists'],
                    ['need' => 'Who is suitable', 'state' => 'Covered', 'why' => 'Suitability section on /implant'],
                    ['need' => 'Treatment process', 'state' => 'Weak', 'why' => 'Process briefly mentioned; shallow depth'],
                    ['need' => 'Recovery', 'state' => 'Missing', 'why' => 'No dedicated supporting content classified'],
                    ['need' => 'Alternatives', 'state' => 'Covered', 'why' => 'Comparison section present'],
                    ['need' => 'Common questions', 'state' => 'Partial', 'why' => 'FAQ CPT exists but thin on recovery'],
                    ['need' => 'Location / travel', 'state' => 'Missing', 'why' => 'EU travel intent underserved'],
                    ['need' => 'Specialist authority', 'state' => 'Covered', 'why' => '/team + doctor CPTs'],
                ],
                'limitation' => null,
            ],
            'topic_cluster' => [
                'name' => 'Implants',
                'nodes' => [
                    ['role' => 'Main service', 'page' => '/implant', 'state' => 'Present'],
                    ['role' => 'Candidate suitability', 'page' => '/implant#suitability', 'state' => 'Present'],
                    ['role' => 'Procedure / process', 'page' => '/implant', 'state' => 'Weak'],
                    ['role' => 'Recovery', 'page' => null, 'state' => 'Missing'],
                    ['role' => 'Cost considerations', 'page' => '/implant', 'state' => 'Weak'],
                    ['role' => 'Alternatives', 'page' => '/implant#alternatives', 'state' => 'Present'],
                    ['role' => 'FAQs', 'page' => '/faq?topic=implant', 'state' => 'Partial'],
                    ['role' => 'Specialist expertise', 'page' => '/team', 'state' => 'Present'],
                ],
            ],
            'gaps' => [
                [
                    'title' => 'Implant recovery expectations',
                    'why' => [
                        'Brand sells Implant treatment',
                        'Related query cluster is observed',
                        'Existing service page does not substantially cover this intent',
                        'No dedicated supporting content was classified',
                    ],
                    'audience' => 'Potential implant patients',
                    'suggested_role' => 'Supporting article or service section',
                    'sources' => ['Brand Context', 'GSC', 'Content inventory'],
                ],
            ],
            'decay' => [
                [
                    'page' => '/dental-implants/',
                    'clicks_delta' => '−31%',
                    'impressions_delta' => '−18%',
                    'position' => '6.2 → 10.4',
                    'last_modified' => '14 months ago',
                    'state' => 'Potential content decay',
                    'window' => $f['label'],
                ],
            ],
            'internal_linking' => [
                ['signal' => 'Orphan candidates', 'value' => '3 draft/orphaned URLs'],
                ['signal' => 'Broken internal links', 'value' => '12'],
                ['signal' => 'Hub support for /implant', 'value' => 'Weak from blog cluster'],
                ['signal' => 'Crawl depth outliers', 'value' => '4 URLs depth ≥ 5'],
            ],
            'media' => [
                'image_count' => 712,
                'oversized_candidates' => 18,
                'missing_alt_candidates' => 64,
                'broken_images' => 2,
                'note' => 'Grouped candidates — not one Finding per image.',
            ],
            'trends' => [
                [
                    'topic' => 'All-on-4 recovery time',
                    'market' => 'United Kingdom',
                    'language' => 'English',
                    'trend' => 'Increasing over recent observation window',
                    'why' => 'Matches Implant offering and UK target market',
                    'coverage' => 'Weak',
                    'source' => 'Trend Intelligence · Demo fixture · relative interest ≠ volume',
                ],
            ],
            'debt' => [
                '8 priority offerings have incomplete coverage',
                '14 pages have no clear content role',
                '9 high-visibility pages have not been reviewed recently',
                '7 pages show potential query overlap',
                '18 supporting articles have no path to a priority service',
            ],
            'architecture' => [
                'Home',
                '└─ Treatments',
                '   ├─ Implant',
                '   │  ├─ Recovery article (missing)',
                '   │  ├─ FAQ (partial)',
                '   │  └─ Cost considerations (weak)',
                '   └─ Orthodontics',
                '└─ Post-bariatric',
                '└─ Team',
                '└─ Contact',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function performanceWorkspace(array $f, array $base, string $preset = 'last_28'): array
    {
        $rf = $f['results_factor'] ?? 1.0;

        return [
            'sub' => ['search' => 'Search', 'acquisition' => 'Acquisition', 'landing' => 'Landing Pages', 'conversions' => 'Conversions', 'outcome' => 'Search-to-Outcome'],
            'search' => $base['search'] ?? [],
            'vitals' => $base['performance'] ?? DemoCatalog::websitePerformance($preset),
            'acquisition' => [
                'sessions' => (int) round(23840 * $rf),
                'users' => (int) round(19200 * $rf),
                'engaged_rate' => 62.4,
                'sources' => [
                    ['source' => 'Organic Search', 'sessions' => (int) round(9800 * $rf), 'label' => 'GA4 · measured'],
                    ['source' => 'Paid Social', 'sessions' => (int) round(6200 * $rf), 'label' => 'GA4 · measured'],
                    ['source' => 'Paid Search', 'sessions' => (int) round(4100 * $rf), 'label' => 'GA4 · measured'],
                    ['source' => 'Direct', 'sessions' => (int) round(2400 * $rf), 'label' => 'GA4 · measured'],
                    ['source' => 'chatgpt.com / referral', 'sessions' => (int) round(42 * $rf), 'label' => 'GA4 · measured'],
                ],
                'window' => $f['label'],
            ],
            'landing_pages' => [
                [
                    'path' => '/implant',
                    'sessions' => (int) round(6200 * $rf),
                    'engagement' => '58%',
                    'events' => (int) round(88 * $rf),
                    'organic_clicks' => (int) round(2100 * $rf),
                    'role' => 'Service / Product',
                    'state' => 'LCP Finding',
                ],
                [
                    'path' => '/post-bariatric',
                    'sessions' => (int) round(4100 * $rf),
                    'engagement' => '61%',
                    'events' => (int) round(54 * $rf),
                    'organic_clicks' => (int) round(860 * $rf),
                    'role' => 'Service / Product',
                    'state' => '—',
                ],
                [
                    'path' => '/',
                    'sessions' => (int) round(3800 * $rf),
                    'engagement' => '49%',
                    'events' => (int) round(22 * $rf),
                    'organic_clicks' => (int) round(1200 * $rf),
                    'role' => 'Home',
                    'state' => '—',
                ],
                [
                    'path' => '/contact',
                    'sessions' => (int) round(2100 * $rf),
                    'engagement' => '66%',
                    'events' => (int) round(31 * $rf),
                    'organic_clicks' => (int) round(520 * $rf),
                    'role' => 'Conversion / Contact',
                    'state' => 'Local mismatch',
                ],
            ],
            'conversion_mapping' => [
                ['business_action' => 'Form submission', 'ga4_event' => 'generate_lead', 'mapped' => true],
                ['business_action' => 'Phone call', 'ga4_event' => 'phone_click', 'mapped' => true],
                ['business_action' => 'WhatsApp click', 'ga4_event' => null, 'mapped' => false],
                ['business_action' => 'Appointment', 'ga4_event' => null, 'mapped' => false],
            ],
            'measurement_debt' => [
                'WhatsApp CTA exists on 23 pages, but no WhatsApp business action is mapped',
                'Appointment goal not mapped to a discovered GA4 event',
            ],
            'search_to_outcome' => [
                [
                    'cluster' => 'Dental implants Turkey',
                    'visibility' => 'High',
                    'landing' => '/dental-implants/',
                    'engagement' => 'Strong',
                    'actions' => '14 form submissions observed on landing (page-level) · not query-attributed',
                    'disclaimer' => 'GSC query data cannot prove which query caused a GA4 conversion.',
                ],
            ],
            'change_impact' => [
                [
                    'change' => 'Service page rewritten',
                    'when' => '12 Jul 2026',
                    'window' => 'Following 28 days vs previous comparable period',
                    'impressions' => '+18%',
                    'clicks' => '+11%',
                    'events' => '+4',
                    'outcome' => 'Improvement observed',
                    'label' => 'Observed after change — not caused by change',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function connections(): array
    {
        return [
            'data_sources' => [
                [
                    'name' => 'WordPress',
                    'state' => 'Connected',
                    'detail' => 'atlasdental.example · application password present',
                    'last' => '4 hours ago',
                    'provides' => ['CMS inventory', 'CPT discovery', 'Theme/plugin signals'],
                    'action' => 'Manage binding',
                    'action_note' => 'Demo Mode · binding UI placeholder',
                ],
                [
                    'name' => 'Google Search Console',
                    'state' => 'Connected',
                    'detail' => 'Atlas Dental · sc-domain:atlasdental.example',
                    'last' => '2 hours ago',
                    'provides' => ['Search visibility', 'Queries', 'Pages', 'Country/device'],
                    'action' => 'Open Search Console asset',
                    'route' => 'operator.search-console',
                ],
                [
                    'name' => 'Google Analytics (GA4)',
                    'state' => 'Connected',
                    'detail' => 'Atlas Dental GA4 property',
                    'last' => '2 hours ago',
                    'provides' => ['Acquisition', 'Landing behaviour', 'Configured key events'],
                    'action' => 'Open Analytics asset',
                    'route' => 'operator.analytics',
                ],
            ],
            'related_assets' => [
                [
                    'name' => 'Google Ads',
                    'detail' => 'Atlas Dental — Google Ads',
                    'note' => 'Related Brand asset · Website ↔ Google Ads checks available',
                    'route' => 'operator.google-ads.overview',
                ],
                [
                    'name' => 'Meta Ads',
                    'detail' => 'Atlas Dental — Meta',
                    'note' => 'Related Brand asset · landing-page performance relationship',
                    'route' => 'operator.meta.overview',
                ],
                [
                    'name' => 'Google Business Profile',
                    'detail' => 'Atlas Dental Ankara',
                    'note' => 'Related Brand asset · entity consistency with Website',
                    'route' => 'operator.gbp',
                ],
            ],
            'note' => 'GA4 and Search Console are Website data sources — not separate Website Digital Assets. Google Ads / Meta / GBP remain independent Digital Assets.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activity(): array
    {
        return [
            ['when' => 'Today 14:10', 'category' => 'diagnosis', 'title' => 'Website diagnosis completed', 'detail' => '34 checks · 12 open findings'],
            ['when' => 'Today 12:02', 'category' => 'collection', 'title' => 'Search Console collection completed', 'detail' => 'Through 10 Aug'],
            ['when' => 'Today 12:00', 'category' => 'collection', 'title' => 'GA4 collection completed', 'detail' => 'Through 11 Aug'],
            ['when' => 'Yesterday', 'category' => 'seo', 'title' => 'SEO intelligence refresh completed', 'detail' => 'DataForSEO · estimated · paid MISS confirmed'],
            ['when' => 'Yesterday', 'category' => 'discovery', 'title' => 'Public discovery completed', 'detail' => '8 pages inspected'],
            ['when' => '2 days ago', 'category' => 'ai', 'title' => 'AI Guidance generated', 'detail' => 'Demo Mode · no live model call'],
            ['when' => '3 days ago', 'category' => 'operator', 'title' => 'Website Settings updated', 'detail' => 'Search market TR / Turkish confirmed'],
            ['when' => '5 days ago', 'category' => 'failure', 'title' => 'WordPress collection retried after timeout', 'detail' => 'Recovered on second attempt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'name' => 'Atlas Dental Website',
            'domain' => 'atlasdental.example',
            'primary_url' => 'https://atlasdental.example',
            'cms' => 'WordPress',
            'website_type' => 'Marketing / brochure + conversion',
            'languages' => ['Turkish', 'English'],
            'target_countries' => ['Türkiye', 'Germany', 'United Kingdom'],
            'search_market' => ['country' => 'Türkiye', 'language' => 'Turkish'],
            'hosting_context' => 'DemoHost · production',
            'brand_context_note' => 'Offerings, audiences, and goals inherit from Brand Business Context. Do not duplicate Brand facts here.',
            'event_mapping' => [
                ['business_action' => 'Form submission', 'ga4_event' => 'generate_lead'],
                ['business_action' => 'Phone call', 'ga4_event' => 'phone_click'],
                ['business_action' => 'WhatsApp click', 'ga4_event' => '— not mapped —'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function aiGuidance(): array
    {
        return [
            'what_matters' => [
                'Canonical template gap affects 27 service pages — developer-owned fix with broad index impact.',
                'Mobile LCP on /implant amplifies paid and organic inefficiency.',
                'WhatsApp measurement gap hides a likely conversion path.',
                'Implant recovery content gap leaves observed demand without adequate coverage.',
            ],
            'why' => 'Deterministic diagnosis + Search Console + Brand Context converge on the same priority cluster.',
            'evidence' => ['Finding · canonical template', 'Finding · /implant LCP', 'GSC · implant ankara / fiyat', 'Brand Context · Implant offering'],
            'next_step' => 'Fix developer-owned template/LCP work, map WhatsApp measurement, then review the implant recovery content opportunity.',
            'uncertainty' => 'AI Visibility observations are Demo fixtures only. Trend interest is relative, not volume.',
            'disclaimer' => 'AI Guidance is derived interpretation. It cannot create Findings, Tasks, or external writes.',
        ];
    }
}
