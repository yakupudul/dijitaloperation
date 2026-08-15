<?php

namespace App\Support\Playbooks;

/**
 * Seed-only curated default Playbook definitions (not a runtime Demo fixture).
 *
 * Classification: CURATED_PRODUCT_CONTENT / CANONICAL_DEFAULT_CONTENT.
 * Atlas-specific customer wording removed. related_ai_skill is display knowledge only.
 */
final class DefaultPlaybookCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'stable_key' => 'pb-weekly-gads',
                'title' => 'Weekly Google Ads Review',
                'summary' => 'Monitor paid search efficiency, waste, and conversion signal health.',
                'cadence' => 'weekly',
                'service_codes' => ['google_ads'],
                'asset_types' => ['google_ads'],
                'execution_scopes' => ['digital_asset'],
                'knowledge' => [
                    'purpose' => 'Monitor paid search efficiency, waste, and conversion signal health.',
                    'when_to_use' => [
                        'Google Ads Management service is active.',
                        'Account has spend and conversion data for the prior week.',
                    ],
                    'when_not_to_use' => [
                        'Service is paused.',
                        'Account has no usable data.',
                        'Campaigns have not started yet.',
                    ],
                    'methodology' => [
                        'Inspect meaningful-spend search terms.',
                        'Separate intent mismatch from low-volume noise.',
                        'Check Goal / Offering relevance.',
                        'Review landing-page alignment.',
                        'Produce Finding, Opportunity, or No Issue.',
                    ],
                    'qa_guidance' => [
                        'Conversion event still mapping to the intended Business Action',
                        'No unintended budget or bid changes left unpublished',
                        'Findings logged with Evidence links where applicable',
                    ],
                    'related_ai_skill_label' => 'Search Query Analysis',
                    'related_ai_skill_note' => 'AI Skill may assist this Playbook — it does not replace the operating standard.',
                ],
                'instructions' => [
                    ['body' => 'Run every Monday before noon. Compare against prior week. Escalate measurement gaps immediately.'],
                    ['body' => 'Confirm primary conversion signal is firing'],
                    ['body' => 'Review search-term waste and negatives'],
                    ['body' => 'Check campaign budget pacing'],
                    ['body' => 'Inspect landing page alignment for top campaigns'],
                    ['body' => 'Log findings or opportunities if thresholds breached'],
                ],
                'references' => [
                    ['kind' => 'internal_route', 'label' => 'Google Ads workspace', 'route_name' => 'demo.google-ads.overview'],
                    ['kind' => 'internal_route', 'label' => 'Findings', 'route_name' => 'demo.findings'],
                ],
            ],
            [
                'stable_key' => 'pb-monthly-seo',
                'title' => 'Monthly SEO Coverage Review',
                'summary' => 'Assess organic visibility, content coverage, and indexing health.',
                'cadence' => 'monthly',
                'service_codes' => ['seo'],
                'asset_types' => ['gsc', 'website'],
                'execution_scopes' => ['digital_asset'],
                'knowledge' => [
                    'purpose' => 'Assess organic visibility, content coverage, and indexing health.',
                    'when_to_use' => [
                        'SEO / Organic Growth service is active.',
                        'Search Console asset exists for the Brand.',
                    ],
                    'when_not_to_use' => [
                        'SEO service paused.',
                        'No Search Console property connected.',
                    ],
                    'methodology' => [
                        'Compare priority offering queries vs content ownership.',
                        'Inspect indexing health on money pages.',
                        'Cross-check paid demand without organic coverage.',
                        'Log Opportunity or Task only when action is warranted.',
                    ],
                    'qa_guidance' => [
                        'Opportunities cite Goal and Service Scope',
                        'No fake Opportunity score assigned',
                    ],
                ],
                'instructions' => [
                    ['body' => 'Run first business day of the month. Cross-reference GSC and Website health tabs.'],
                    ['body' => 'Review priority query coverage vs goals'],
                    ['body' => 'Check indexing and crawl anomalies'],
                    ['body' => 'Compare content depth for priority offerings'],
                    ['body' => 'Identify cross-channel gaps with paid demand'],
                    ['body' => 'Document opportunities or tasks'],
                ],
                'references' => [
                    ['kind' => 'internal_route', 'label' => 'Search Console workspace', 'route_name' => 'demo.search-console'],
                    ['kind' => 'internal_route', 'label' => 'Website workspace', 'route_name' => 'demo.website'],
                ],
            ],
            [
                'stable_key' => 'pb-meta-creative',
                'title' => 'Meta Creative Review',
                'summary' => 'Review creative fatigue, frequency, and CPL trends on Meta campaigns.',
                'cadence' => 'weekly',
                'service_codes' => ['meta_ads'],
                'asset_types' => ['meta_ads'],
                'execution_scopes' => ['digital_asset'],
                'knowledge' => [
                    'purpose' => 'Review creative fatigue, frequency, and CPL trends on Meta campaigns.',
                    'when_to_use' => [
                        'Meta Ads Management service is active.',
                        'Campaigns have delivered in the review window.',
                    ],
                    'when_not_to_use' => [
                        'Meta service paused.',
                        'No creative spend in the period.',
                    ],
                    'methodology' => [
                        'Rank ad sets by spend and CPL drift.',
                        'Inspect frequency and CTR decay on top creatives.',
                        'Confirm landing destinations still match offers.',
                        'Queue refresh Task only when thresholds breach.',
                    ],
                    'qa_guidance' => [
                        'Creative replacements reviewed on mobile',
                        'Destination URLs verified',
                    ],
                ],
                'instructions' => [
                    ['body' => 'Run mid-week. Pair with Meta workspace insights tab.'],
                    ['body' => 'Inspect top-spend ad sets for CPL drift'],
                    ['body' => 'Check creative frequency and CTR decay'],
                    ['body' => 'Review audience overlap signals'],
                    ['body' => 'Validate landing destinations'],
                    ['body' => 'Queue creative refresh task if needed'],
                ],
                'references' => [
                    ['kind' => 'internal_route', 'label' => 'Meta Ads workspace', 'route_name' => 'demo.meta.overview'],
                ],
            ],
            [
                'stable_key' => 'pb-website-health',
                'title' => 'Website Health Review',
                'summary' => 'Monitor performance, uptime signals, and critical page health.',
                'cadence' => 'monthly',
                'service_codes' => ['website_maintenance'],
                'asset_types' => ['website'],
                'execution_scopes' => ['digital_asset'],
                'knowledge' => [
                    'purpose' => 'Monitor performance, uptime signals, and critical page health.',
                    'when_to_use' => [
                        'Website Management / Maintenance is in Service Scope.',
                        'Website asset exists.',
                    ],
                    'when_not_to_use' => [
                        'Website service not in scope.',
                        'Site is offline for planned maintenance already tracked.',
                    ],
                    'methodology' => [
                        'Check Core Web Vitals on money pages.',
                        'Verify redirects and SSL.',
                        'Confirm critical forms still convert.',
                        'Log Task only for actionable regressions.',
                    ],
                    'qa_guidance' => [
                        'Correct target URL after content changes',
                        'Mobile layout reviewed',
                        'Conversion event verified',
                        'No unintended content change',
                    ],
                ],
                'instructions' => [
                    ['body' => 'Run monthly after analytics close. No provider writes from MoxDOP.'],
                    ['body' => 'Review Core Web Vitals on priority URLs'],
                    ['body' => 'Check SSL and redirect integrity'],
                    ['body' => 'Scan for broken links on key funnels'],
                    ['body' => 'Confirm connector health'],
                    ['body' => 'Log maintenance tasks if regressions found'],
                ],
                'references' => [
                    ['kind' => 'internal_route', 'label' => 'Website workspace', 'route_name' => 'demo.website'],
                ],
            ],
        ];
    }
}
