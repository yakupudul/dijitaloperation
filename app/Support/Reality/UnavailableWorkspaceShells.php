<?php

namespace App\Support\Reality;

use App\Models\DigitalAsset;

/**
 * Truthful empty/unavailable specialist shells for production Digital Assets
 * when a specialist workspace has no real production read path yet (Prompt 67).
 *
 * Never substitute Demo fixtures for numeric production asset ids.
 */
final class UnavailableWorkspaceShells
{
    /**
     * @return array<string, mixed>
     */
    public static function website(string $assetId): array
    {
        $asset = self::asset($assetId);
        $unavailableChip = [
            'value' => '—',
            'secondary' => 'Unavailable',
        ];

        return [
            'migration_mode' => 'unavailable',
            'demo_boundary' => 'Production Website specialist analytics are not yet wired to canonical observations for this asset — no Demo fixtures are shown.',
            'identity' => [
                'eyebrow' => 'Website',
                'title' => ($asset?->name ?? 'Website').' — not collected',
                'brand' => $asset?->brand?->name,
                'brand_id' => $asset?->brand_id ?? 0,
                'brand_name' => $asset?->brand?->name ?? '—',
                'domain' => $asset?->name ?? '—',
                'primary_url' => '#',
                'cms' => 'Unavailable',
                'languages' => '—',
                'market' => '—',
                'status' => 'Unavailable',
                'status_note' => 'No Demo fixtures — Website specialist analytics are not wired for this production asset yet.',
                'last_refresh' => '—',
                'freshness' => null,
            ],
            'source_freshness' => [],
            'glance' => [
                'open_findings' => 0,
                'high_findings' => 0,
                'active_tasks' => 0,
                'overdue_tasks' => 0,
                'search_visibility' => $unavailableChip,
                'site_inventory' => $unavailableChip,
            ],
            'needs_attention' => [],
            'opportunities' => [],
            'inventory_snapshot' => [
                'label' => 'Unavailable',
                'pages' => '—',
                'posts' => '—',
                'custom_types' => '—',
                'media' => '—',
                'sitemap_urls' => '—',
                'reconciliation' => ['note' => 'No inventory observations collected yet.'],
            ],
            'search_snapshot' => [
                'gsc_label' => 'Unavailable',
                'window' => '—',
                'clicks' => 0,
                'impressions' => 0,
                'striking_distance' => '—',
                'dataforseo_opportunities' => '—',
            ],
            'conversion_snapshot' => [
                'mapped' => [],
                'gaps' => [],
            ],
            'recent_outcomes' => [],
            'period_label' => '—',
            'health' => [
                'findings' => [],
                'groups' => [],
                'note' => 'No Website health observations collected yet.',
                'summary' => [
                    'checks_evaluated' => 0,
                    'findings_open' => 0,
                    'high_severity' => 0,
                    'checks_unavailable' => 0,
                ],
                'wordpress' => [
                    'version' => '—',
                    'theme' => '—',
                    'plugin_count' => 0,
                    'plugin_updates' => 0,
                    'rest_state' => 'Not collected',
                    'note' => 'WordPress / CMS health is unavailable until a Site Connector is configured.',
                ],
                'availability' => [
                    'configured' => false,
                    'demo_note' => 'Availability monitoring is not configured for this production Website.',
                    'demo_timeline' => [],
                ],
            ],
            'visibility' => [
                'lenses' => ['organic' => 'Organic Search', 'local' => 'Local', 'ai' => 'AI Visibility'],
                'organic' => [
                    'window' => '—',
                    'source' => 'Unavailable — no Search Console observations collected',
                    'kpis' => ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'avg_position' => 0],
                    'groups' => [
                        'growing' => [],
                        'declining' => [],
                        'striking_distance' => [],
                        'high_impression_low_ctr' => [],
                        'new_visibility' => [],
                        'lost_visibility' => [],
                    ],
                    'query_pages' => [],
                    'dataforseo' => [
                        'ranked_keywords' => 0,
                        'keywords_for_site' => 0,
                        'opportunities' => 0,
                        'label' => 'DataForSEO · not collected',
                        'guard' => 'Paid refresh is not triggered on page render.',
                    ],
                ],
                'local' => [
                    'active' => false,
                    'reason' => 'Local visibility has not been measured for this Website.',
                    'signals' => [],
                    'matrix' => [
                        'headers' => ['Service', 'Coverage'],
                        'rows' => [],
                        'note' => 'Controllable Website/Brand signals — not a local ranking guarantee.',
                    ],
                    'gbp' => [
                        'asset_name' => '—',
                        'route' => 'demo.assets',
                        'note' => 'No related GBP observations for this production Website.',
                    ],
                ],
                'ai' => [
                    'readiness' => [],
                    'readiness_note' => 'AI readiness has not been measured for this production Website.',
                    'observed' => [
                        'measured' => false,
                        'demo_rows' => [],
                        'sample_note' => 'AI visibility has not been measured in production.',
                    ],
                    'referrals' => [
                        'label' => 'AI-assisted referral traffic',
                        'sessions' => 0,
                        'source' => 'Unavailable',
                        'note' => 'Referral traffic ≠ share of AI mentions.',
                    ],
                    'generative_search' => [
                        'available' => false,
                        'message' => 'Generative search reporting is not collected.',
                    ],
                ],
                'competitors' => [
                    'capability' => 'Bounded Discovery only',
                    'known' => [],
                    'note' => 'No competitor crawl is shown without Evidence.',
                ],
            ],
            'content_workspace' => [
                'inventory' => [],
                'roles' => [],
                'directory' => [],
                'coverage' => ['offering' => '—', 'rows' => []],
                'topic_cluster' => ['name' => '—', 'nodes' => []],
                'gaps' => [],
                'decay' => [],
                'trends' => [],
                'internal_linking' => [],
                'media' => [
                    'image_count' => 0,
                    'oversized_candidates' => 0,
                    'missing_alt_candidates' => 0,
                    'broken_images' => 0,
                    'note' => 'No media inventory collected for this Website.',
                ],
                'debt' => [],
                'architecture' => [],
            ],
            'performance_workspace' => [
                'sub' => [
                    'search' => 'Search',
                    'acquisition' => 'Acquisition',
                    'landing' => 'Landing Pages',
                    'conversions' => 'Conversions',
                    'outcome' => 'Search-to-Outcome',
                ],
                'search' => ['kpis' => [], 'top_queries' => []],
                'vitals' => [],
                'acquisition' => [
                    'sessions' => 0,
                    'users' => 0,
                    'engaged_rate' => 0,
                    'sources' => [],
                    'window' => '—',
                ],
                'landing_pages' => [],
                'conversion_mapping' => [],
                'measurement_debt' => ['No conversion mapping has been collected for this Website.'],
                'search_to_outcome' => [],
                'change_impact' => [],
                'outcome_note' => 'Observed after change — not caused by change.',
            ],
            'connections' => [
                'note' => 'Website data sources are not configured. Domain, DNS, Hosting and SSL remain Website infrastructure — not standalone assets.',
                'data_sources' => [],
                'related_assets' => [],
            ],
            'activity' => [],
            'settings' => [
                'name' => $asset?->name ?? 'Website',
                'domain' => $asset?->domain ?? ($asset?->name ?? '—'),
                'primary_url' => $asset?->primary_url ?? '—',
                'cms' => 'Unavailable',
                'website_type' => '—',
                'hosting_context' => 'Not collected',
                'languages' => [],
                'target_countries' => [],
                'search_market' => ['country' => '—', 'language' => '—'],
                'brand_context_note' => 'Offerings, audiences, and goals inherit from Brand Business Context when collected.',
                'event_mapping' => [],
            ],
            'infrastructure' => [
                'subtitle' => 'Domain, DNS, hosting, CDN, SSL and CMS belong to the Website Digital Asset — not standalone assets.',
                'attention' => [],
                'domain' => [
                    'hostname' => $asset?->domain ?? ($asset?->name ?? '—'),
                    'registrar' => '—',
                    'registered_at' => '—',
                    'expires_at' => '—',
                    'auto_renew' => false,
                    'provenance' => 'Not collected',
                ],
                'dns' => [
                    'state' => 'Not collected',
                    'nameservers' => [],
                    'records' => [],
                ],
                'hosting' => [
                    'provider' => '—',
                    'platform' => '—',
                    'region' => '—',
                    'environment' => '—',
                    'renewal_at' => '—',
                    'provenance' => 'Not collected',
                ],
                'cdn' => [
                    'provider' => '—',
                    'state' => 'Not collected',
                    'note' => 'CDN is Website infrastructure, not a standalone Digital Asset.',
                ],
                'ssl' => [
                    'https' => '—',
                    'issuer' => '—',
                    'grade' => '—',
                    'expires_at' => '—',
                    'days_remaining' => '—',
                    'provenance' => 'Not collected',
                ],
                'cms' => [
                    'name' => '—',
                    'version' => '—',
                    'provenance' => 'Not collected',
                ],
                'findings' => [],
                'legacy_note' => 'Domain and Hosting are not standalone Digital Assets.',
            ],
            'ai_guidance' => [
                'what_matters' => [],
                'next_step' => 'Connect Website observations / WordPress / GSC sources before expecting specialist analytics.',
                'evidence' => [],
                'disclaimer' => 'No Demo AI guidance is shown for production assets without collected observations.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gbp(string $assetId): array
    {
        $asset = self::asset($assetId);
        $brandId = $asset?->brand_id ?? 0;
        $brandName = $asset?->brand?->name ?? '—';
        $unavailableGlance = [
            'value' => '—',
            'label' => 'Not collected',
        ];

        return [
            'migration_mode' => 'unavailable',
            'demo_boundary' => 'Production GBP specialist analytics are not fully productionized for this asset — no Demo fixtures, fake ranks, or sample reviews are shown.',
            'identity' => [
                'eyebrow' => 'Google Business Profile',
                'title' => ($asset?->name ?? 'GBP').' — not collected',
                'brand' => $brandName,
                'brand_id' => $brandId,
                'brand_name' => $brandName,
                'website_asset_id' => self::siblingWebsiteId($asset),
                'status' => 'Unavailable',
                'locale' => '—',
                'lat' => null,
                'lng' => null,
                'location_line' => '—',
            ],
            'glance' => [
                'profile' => $unavailableGlance,
                'visibility' => $unavailableGlance,
                'reviews' => $unavailableGlance,
                'actions' => $unavailableGlance,
            ],
            'profile_coverage' => [
                'present' => 0,
                'total_reviewed' => 0,
                'need_attention' => 0,
                'unavailable' => 0,
                'note' => 'Profile fields have not been collected for this production GBP.',
                'groups' => [],
            ],
            'review_pulse' => [
                'rating' => '—',
                'total' => 0,
                'new' => 0,
                'unanswered' => 0,
                'attention' => 0,
                'needs_attention_themes' => [],
                'positive' => [],
                'provenance' => 'Not collected',
            ],
            'customer_actions' => [
                'period' => '—',
                'search_impressions' => 0,
                'maps_impressions' => 0,
                'website_clicks' => 0,
                'call_clicks' => 0,
                'direction_requests' => 0,
            ],
            'visibility_snapshot' => [
                'keyword' => '—',
                'scanned_at' => '—',
                'source' => 'Not collected',
                'average_rank' => '—',
                'top3' => '—',
                'top10' => '—',
                'weakest_area' => '—',
            ],
            'website_consistency' => [],
            'recent_outcomes' => [],
            'needs_attention' => [],
            'opportunities' => [],
            'profile' => [
                'subtitle' => 'GBP profile fields are unavailable until the location is bound and collected.',
                'fields' => [],
                'categories' => [
                    'primary' => '—',
                    'additional' => [],
                    'note' => 'No category observations collected.',
                    'offering_map' => [],
                ],
                'services' => [],
                'location' => [
                    'address' => '—',
                    'lat' => null,
                    'lng' => null,
                    'service_area' => '—',
                    'website_location_page' => '—',
                    'website_location_state' => 'Not collected',
                    'note' => 'Location has not been collected for this production GBP.',
                ],
                'media' => [
                    'profile_photo' => false,
                    'cover_photo' => false,
                    'merchant_count' => 0,
                    'customer_count' => 0,
                    'note' => 'No GBP media collected.',
                ],
            ],
            'visibility' => [
                'subtitle' => 'Local visibility has not been measured for this production GBP.',
                'grid' => [],
                'keywords' => [],
                'default_keyword' => '',
                'scans' => [],
                'coverage_regions' => [],
                'comparison' => [],
                'opportunities' => [],
                'note' => 'Local visibility grid is unavailable — no fabricated rankings.',
            ],
            'performance' => [
                'subtitle' => 'GBP performance is unavailable until Insights are collected.',
                'period' => '—',
                'previous_label' => 'prior period',
                'queries' => [
                    'rows' => [],
                    'period' => '—',
                    'intent_provenance' => 'Not collected',
                ],
                'discovery' => [
                    'source' => 'Not collected',
                    'search_impressions' => 0,
                    'search_delta' => 0,
                    'maps_impressions' => 0,
                    'maps_delta' => 0,
                    'total_impressions' => 0,
                    'series_search' => ['labels' => [], 'values' => []],
                    'series_maps' => ['labels' => [], 'values' => []],
                ],
                'actions' => [
                    'website_clicks' => 0,
                    'website_delta' => 0,
                    'call_clicks' => 0,
                    'call_delta' => 0,
                    'direction_requests' => 0,
                    'direction_delta' => 0,
                    'note' => 'Customer actions have not been collected.',
                    'source' => 'Not collected',
                    'series' => ['labels' => [], 'values' => []],
                ],
            ],
            'reviews' => [
                'subtitle' => 'Reviews have not been collected for this production GBP.',
                'provenance' => 'Not collected',
                'no_write' => 'No Google reply write actions.',
                'glance' => [
                    'rating' => '—',
                    'total' => 0,
                    'new' => 0,
                    'needs_reply' => 0,
                    'attention' => 0,
                ],
                'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
                'inbox' => [],
                'topics' => [],
                'queue' => [],
                'topic_trend' => [
                    'note' => 'No review topics collected.',
                    'current' => 0,
                    'previous' => 0,
                ],
            ],
            'competitors' => [
                'subtitle' => 'Observed local competitors are unavailable until a visibility scan exists.',
                'note' => 'No fabricated competitor set is shown.',
                'presence_label' => 'Not collected',
                'presence' => [],
                'rows' => [],
            ],
            'operations' => [
                'subtitle' => 'No GBP findings, recommendations, tasks, or outcomes until canonical observations exist.',
                'findings' => [],
                'recommendations' => [],
                'tasks' => [],
                'outcomes' => [],
            ],
            'health' => ['findings' => []],
            'activity' => [],
            'ai_guidance' => [
                'what_matters' => [],
                'next_step' => 'Bind and collect a Google Business Profile location before expecting specialist analytics.',
                'evidence' => [],
                'disclaimer' => 'No Demo AI guidance is shown for production assets without collected observations.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function instagram(string $assetId): array
    {
        $asset = self::asset($assetId);
        $brandId = $asset?->brand_id ?? 0;
        $brandName = $asset?->brand?->name ?? '—';

        return [
            'migration_mode' => 'unavailable',
            'demo_boundary' => 'Instagram analytics provider integration is unavailable — asset record/setup may exist, but no simulated analytics are shown.',
            'identity' => [
                'asset_id' => $assetId,
                'eyebrow' => 'Instagram',
                'title' => ($asset?->name ?? 'Instagram').' — analytics unavailable',
                'handle' => '—',
                'brand' => $brandName,
                'brand_id' => $brandId,
                'brand_name' => $brandName,
                'connection' => 'Not connected',
                'freshness' => '—',
                'status' => 'Unavailable',
                'status_note' => 'No Demo fixtures — Instagram analytics are unavailable for this production asset.',
            ],
            'overview' => [
                'glance' => [],
                'needs_attention' => [],
                'content_mix' => [],
                'recent_posts' => [],
            ],
            'profile' => [
                'display_name' => $asset?->name ?? '—',
                'username' => '—',
                'category' => '—',
                'bio' => 'Profile analytics are unavailable until a supported Instagram provider path exists.',
                'website' => '—',
                'contact' => [
                    'email' => '—',
                    'phone' => '—',
                ],
                'coverage' => [],
                'consistency' => [
                    'brand_name_match' => false,
                    'website_match' => false,
                    'website_note' => 'No Instagram analytics observations collected for this production asset.',
                    'phone_match' => false,
                ],
            ],
            'relationships' => [
                'linked_assets' => [],
                'cross_checks' => [],
            ],
            'findings' => [],
            'activity' => [],
            'settings' => [
                'connection_mode' => 'Unavailable',
                'write_actions' => 'Disabled (no external write)',
                'sync_cadence' => '—',
                'responsible' => '—',
                'notes' => [
                    'Connect and bind an Instagram resource when provider analytics support is available.',
                    'No sample metrics are shown for production asset ids.',
                ],
            ],
            'tabs' => ['overview', 'profile', 'operations', 'setup'],
        ];
    }

    private static function asset(string $assetId): ?DigitalAsset
    {
        if (! ctype_digit($assetId)) {
            return null;
        }

        return DigitalAsset::query()->with('brand')->find((int) $assetId);
    }

    private static function siblingWebsiteId(?DigitalAsset $asset): ?int
    {
        if ($asset?->brand_id === null) {
            return null;
        }

        $id = DigitalAsset::query()
            ->where('brand_id', $asset->brand_id)
            ->where('type', 'website')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
