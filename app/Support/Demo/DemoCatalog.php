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
     * @return list<array<string, mixed>>
     */
    public static function customerContacts(): array
    {
        return [
            [
                'id' => 'cc-1',
                'customer_id' => self::CUSTOMER_ID,
                'name' => 'Dr. Elif Arslan',
                'role' => 'owner',
                'title' => 'Owner / Founder',
                'email' => 'elif@atlashealth.example',
                'phone' => '+90 532 000 11 22',
            ],
            [
                'id' => 'cc-2',
                'customer_id' => self::CUSTOMER_ID,
                'name' => 'Burak Şen',
                'role' => 'marketing',
                'title' => 'Marketing',
                'email' => 'burak@atlashealth.example',
                'phone' => '+90 532 000 33 44',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function customerActivity(): array
    {
        return [
            ['id' => 'ca1', 'customer_id' => self::CUSTOMER_ID, 'category' => 'findings', 'title' => 'Finding opened — Meta CPL deteriorated', 'when' => '2 days ago'],
            ['id' => 'ca2', 'customer_id' => self::CUSTOMER_ID, 'category' => 'tasks', 'title' => 'Task created — Replace Meta creative', 'when' => '2 days ago'],
            ['id' => 'ca3', 'customer_id' => self::CUSTOMER_ID, 'category' => 'portfolio', 'title' => 'Digital Asset refresh — Website crawl complete', 'when' => '3 days ago'],
            ['id' => 'ca4', 'customer_id' => self::CUSTOMER_ID, 'category' => 'recommendations', 'title' => 'Recommendation approved — Fix mobile LCP', 'when' => '5 days ago'],
            ['id' => 'ca5', 'customer_id' => self::CUSTOMER_ID, 'category' => 'connections', 'title' => 'Meta import completed for Atlas Dental', 'when' => '1 week ago'],
            ['id' => 'ca6', 'customer_id' => self::CUSTOMER_ID, 'category' => 'portfolio', 'title' => 'Brand updated — Atlas Dental Ankara', 'when' => '2 weeks ago'],
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
     * Operator-owned Business Context snapshot (demo — factual, not AI).
     *
     * @return array<string, mixed>
     */
    public static function brandBusinessContext(): array
    {
        return [
            'brand_id' => self::BRAND_ID,
            'completed' => 6,
            'total' => 8,
            'updated_at' => 'Today at 14:20',
            'updated_by' => 'Ayşe Demir',
            'source' => 'Operator maintained',
            'business_summary' => 'Atlas Dental Ankara is a private dental clinic focused on implantology and post-bariatric dental rehabilitation for local and medical-travel patients.',
            'business_model' => 'Clinic / appointment-led healthcare services',
            'products_services' => ['Dental implants', 'Post-bariatric dentistry', 'Smile design', 'General dentistry'],
            'priority_offerings' => ['Dental implants', 'Post-bariatric dentistry', 'Smile design'],
            'target_audiences' => ['Adults seeking implants in Ankara', 'EU medical-travel patients for complex implant cases'],
            'target_markets' => ['TR', 'DE', 'GB'],
            'business_goals' => ['Increase qualified implant consultations', 'Improve local map visibility for implant demand', 'Stabilize paid lead efficiency'],
            'conversion_goals' => ['Consultation booking', 'WhatsApp / phone inquiry', 'Form lead'],
            'positioning' => 'Specialist implant and post-bariatric dental care with multilingual support for medical travel.',
            'differentiators' => ['Post-bariatric specialty pathway', 'Multilingual care coordination', 'Local + EU demand coverage'],
            'known_competitors' => [
                ['name' => 'Ankara Implant Center', 'url' => null, 'note' => 'Local implant competitor'],
                ['name' => 'Smile Ankara Clinic', 'url' => null, 'note' => 'Aesthetic / smile design overlap'],
            ],
            'important_constraints' => ['No medical claims beyond licensed practice', 'No patient testimonials fabricated for ads'],
            'unknown_areas' => ['Detailed compliance constraints not fully documented', 'Competitor URLs not verified'],
        ];
    }

    /**
     * Cross-channel consistency coverage (demo — no fake score).
     *
     * @return list<array<string, mixed>>
     */
    public static function brandCrossChannel(): array
    {
        return [
            [
                'id' => 'xc-web-gads',
                'check' => 'Website ↔ Google Ads',
                'assets' => ['Website · atlasdental.example', 'Google Ads · Atlas Dental'],
                'state' => 'needs_attention',
                'state_label' => 'Needs attention',
                'last_checked' => '3 hours ago',
                'open_findings' => 1,
                'summary' => 'Landing-page message differs from active Google Ads campaign intent on implant demand.',
                'finding_title' => 'Landing page message mismatch',
                'route' => 'demo.google-ads.overview',
            ],
            [
                'id' => 'xc-web-meta',
                'check' => 'Website ↔ Meta Ads',
                'assets' => ['Website · atlasdental.example', 'Meta Ads · Atlas Dental'],
                'state' => 'needs_attention',
                'state_label' => 'Needs attention',
                'last_checked' => 'Yesterday',
                'open_findings' => 1,
                'summary' => 'Primary Meta landing page also shows weak mobile LCP — inspect together with Meta efficiency.',
                'finding_title' => 'Paid landing page performance risk',
                'route' => 'demo.website',
            ],
            [
                'id' => 'xc-web-gbp',
                'check' => 'Website ↔ Google Business Profile',
                'assets' => ['Website · atlasdental.example', 'GBP · Atlas Dental Ankara'],
                'state' => 'ok',
                'state_label' => 'No mismatch detected',
                'last_checked' => 'Today',
                'open_findings' => 0,
                'summary' => 'NAP and website URL align with the public Maps listing in demo data.',
                'finding_title' => null,
                'route' => 'demo.gbp',
            ],
            [
                'id' => 'xc-web-ig',
                'check' => 'Website ↔ Instagram',
                'assets' => ['Website · atlasdental.example'],
                'state' => 'not_configured',
                'state_label' => 'Not configured',
                'last_checked' => '—',
                'open_findings' => 0,
                'summary' => 'Instagram asset is not available under this brand.',
                'finding_title' => null,
                'route' => null,
            ],
        ];
    }

    /**
     * Discovery candidates for human review (demo).
     *
     * @return list<array<string, mixed>>
     */
    public static function brandDiscoveryCandidates(): array
    {
        return [
            [
                'id' => 'dc-service-implant',
                'kind' => 'fact',
                'kind_label' => 'Discovered fact',
                'value' => 'Dental implants listed as a primary service',
                'type' => 'Listed service',
                'source' => '/treatments/implant',
                'retrieved' => 'Today',
                'status' => 'pending',
                'confidence' => null,
            ],
            [
                'id' => 'dc-location',
                'kind' => 'fact',
                'kind_label' => 'Discovered fact',
                'value' => 'Çankaya, Ankara location visible on contact page',
                'type' => 'Visible location',
                'source' => '/contact',
                'retrieved' => 'Today',
                'status' => 'pending',
                'confidence' => null,
            ],
            [
                'id' => 'dc-lang',
                'kind' => 'fact',
                'kind_label' => 'Discovered fact',
                'value' => 'Turkish + English language switcher detected',
                'type' => 'Website language',
                'source' => 'Site header',
                'retrieved' => 'Today',
                'status' => 'pending',
                'confidence' => null,
            ],
            [
                'id' => 'dc-positioning',
                'kind' => 'inference',
                'kind_label' => 'AI-derived interpretation',
                'value' => 'Likely positioning as specialist implant / post-bariatric dental care',
                'type' => 'Positioning interpretation',
                'source' => 'Homepage + /post-bariatric',
                'retrieved' => 'Today',
                'status' => 'pending',
                'confidence' => 'Medium',
            ],
            [
                'id' => 'dc-audience',
                'kind' => 'inference',
                'kind_label' => 'AI-derived interpretation',
                'value' => 'Probable audience includes medical-travel implant seekers',
                'type' => 'Audience interpretation',
                'source' => 'EU language pages + CTA copy',
                'retrieved' => 'Today',
                'status' => 'pending',
                'confidence' => 'Low',
            ],
            [
                'id' => 'dc-comp-1',
                'kind' => 'competitor',
                'kind_label' => 'Competitor candidate',
                'value' => 'Ankara Implant Center',
                'type' => 'Competitor candidate',
                'source' => 'Public local SERP sample',
                'retrieved' => 'Today',
                'status' => 'pending',
                'confidence' => null,
            ],
        ];
    }

    /**
     * Decision chains for Decision History (not raw sync activity).
     *
     * @return list<array<string, mixed>>
     */
    public static function brandDecisionChains(): array
    {
        return [
            [
                'id' => 'dh-1',
                'date' => '12 Aug 2026',
                'asset' => 'Meta Ads',
                'finding' => 'Meta CPL deterioration on Post Bariatric — Europe',
                'recommendation' => 'Replace underperforming Meta creative PB-Video-03',
                'decision' => 'Accepted by Ayşe Demir',
                'task' => 'Replace Meta creative PB-Video-03',
                'assignee' => 'Ayşe Demir',
                'completed' => '13 Aug 2026',
                'outcome' => 'Improvement observed',
                'outcome_note' => 'Associated improvement observed in follow-up window — not claiming causality.',
                'outcome_date' => '17 Aug 2026',
            ],
            [
                'id' => 'dh-2',
                'date' => '10 Aug 2026',
                'asset' => 'Website',
                'finding' => 'Mobile LCP deteriorated on /implant',
                'recommendation' => 'Fix landing-page mobile LCP',
                'decision' => 'Accepted',
                'task' => 'Fix appointment-form / implant page performance',
                'assignee' => 'Mert Yılmaz',
                'completed' => null,
                'outcome' => 'In progress',
                'outcome_note' => 'Task still open — no outcome yet.',
                'outcome_date' => null,
            ],
            [
                'id' => 'dh-3',
                'date' => '08 Aug 2026',
                'asset' => 'Google Ads',
                'finding' => 'Search-term waste candidates detected',
                'recommendation' => 'Add negatives for Irrelevant / Negative-candidate terms',
                'decision' => 'Pending review',
                'task' => null,
                'assignee' => null,
                'completed' => null,
                'outcome' => null,
                'outcome_note' => 'Recommendation exists without a Task — incomplete chain is valid.',
                'outcome_date' => null,
            ],
            [
                'id' => 'dh-4',
                'date' => '05 Aug 2026',
                'asset' => 'GBP',
                'finding' => 'Unanswered reviews stacking',
                'recommendation' => null,
                'decision' => null,
                'task' => null,
                'assignee' => null,
                'completed' => null,
                'outcome' => null,
                'outcome_note' => 'Finding only — no Recommendation yet.',
                'outcome_date' => null,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function brandRecentActivity(): array
    {
        return [
            ['title' => 'Website technical scan completed', 'when' => 'Today', 'category' => 'sync'],
            ['title' => 'Meta data import refreshed', 'when' => '2 hours ago', 'category' => 'sync'],
            ['title' => 'Public Discovery completed', 'when' => 'Today', 'category' => 'discovery'],
            ['title' => 'Brand AI analysis ready (demo)', 'when' => 'Yesterday', 'category' => 'analysis'],
            ['title' => 'GBP map grid refresh', 'when' => 'Yesterday', 'category' => 'sync'],
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
                'name' => 'Atlas Dental — GA4',
                'brand_id' => self::BRAND_ID,
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'needs_attention',
                'health_label' => 'Needs attention',
                'open_findings' => 3,
                'open_tasks' => 2,
                'last_update' => 'Today',
                'route' => 'demo.analytics',
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
                'route' => 'demo.search-console',
                'relationship_summary' => 'Observes Website · Evidence for SEO / Ads / GBP',
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
                'route' => 'demo.domain',
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
                'route' => 'demo.hosting',
            ],
        ];

        return array_map(static function (array $asset): array {
            return array_merge(
                $asset,
                self::assetTaxonomy((string) $asset['type']),
                self::assetPrimaryMetric($asset),
            );
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
                'primary_metric' => isset($asset['score']) ? (string) $asset['score'].'/100' : '—',
                'primary_metric_label' => 'Health score',
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
     * @return array<string, mixed>
     */
    public static function metaCampaign(string $id, string $preset = 'last_28'): ?array
    {
        return MetaAdsWorkspaceFixtures::campaignDetail($id, $preset);
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
            'attention' => [
                [
                    'severity' => 'high',
                    'title' => 'Mobile LCP on /implant',
                    'body' => 'Lab LCP 4.1s on the primary paid landing page.',
                    'evidence' => 'Field LCP 3.6s · Lab LCP 4.1s (mobile)',
                    'why' => 'Paid and organic traffic land on a slow page.',
                    'source' => 'Performance',
                    'route' => 'demo.website',
                    'route_params' => ['tab' => 'performance'],
                    'action_label' => 'Open performance',
                ],
                [
                    'severity' => 'medium',
                    'title' => 'Broken internal links',
                    'body' => '12 broken internal links across service pages.',
                    'evidence' => 'Crawl · Public + Detected',
                    'why' => 'Crawl waste and thin UX on key paths.',
                    'source' => 'Technical',
                    'route' => 'demo.website',
                    'route_params' => ['tab' => 'health'],
                    'action_label' => 'Open health',
                ],
                [
                    'severity' => 'medium',
                    'title' => 'Hosting renewal due',
                    'body' => 'DemoHost renewal in 34 days.',
                    'evidence' => 'Manual hosting record',
                    'why' => 'Continuity risk if renewal is missed.',
                    'source' => 'Lifecycle',
                    'route' => 'demo.hosting',
                    'route_params' => [],
                    'action_label' => 'Open hosting',
                ],
            ],
            'organic_trend' => self::trendSeries('website_organic', (int) round(14 * $f['results_factor']), 180, 420),
            'traffic_trend' => self::trendSeries('website_sessions', (int) round(14 * $f['results_factor']), 520, 980),
            'technical' => [
                ['severity' => 'high', 'group' => 'critical', 'title' => 'Primary landing page mobile LCP', 'detail' => '4.1s on /implant', 'impact' => 'Paid + organic'],
                ['severity' => 'high', 'group' => 'critical', 'title' => 'Render-blocking hero media', 'detail' => '/implant hero 1.8MB unoptimized', 'impact' => 'Mobile LCP'],
                ['severity' => 'medium', 'group' => 'warnings', 'title' => 'Missing canonical', 'detail' => '7 pages', 'impact' => 'Indexation'],
                ['severity' => 'medium', 'group' => 'warnings', 'title' => 'Broken internal links', 'detail' => '12 links', 'impact' => 'Crawl'],
                ['severity' => 'medium', 'group' => 'warnings', 'title' => 'Orphan blog drafts', 'detail' => '3 drafts not in sitemap', 'impact' => 'Content'],
                ['severity' => 'info', 'group' => 'opportunities', 'title' => 'Schema opportunities', 'detail' => '3 pages missing LocalBusiness / FAQ', 'impact' => 'Rich results'],
                ['severity' => 'info', 'group' => 'opportunities', 'title' => 'Compress chat widget', 'detail' => 'Third-party script adds ~280ms TBT', 'impact' => 'INP / TBT'],
            ],
            'search' => [
                'kpis' => [
                    ['label' => 'Organic Clicks', 'value' => (int) round(8420 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'clicks'), 'tone' => 'good', 'family' => 'delivery'],
                    ['label' => 'Impressions', 'value' => (int) round(186000 * $f['results_factor']), 'format' => 'int', 'delta' => 4.2, 'tone' => 'good', 'family' => 'delivery'],
                    ['label' => 'Avg Position', 'value' => 12.4, 'format' => 'float', 'delta' => -1.1, 'tone' => 'warn', 'family' => 'efficiency'],
                    ['label' => 'Indexed', 'value' => '184 / 197', 'format' => 'text', 'delta' => null, 'tone' => 'neutral', 'family' => 'delivery'],
                ],
                'top_queries' => [
                    ['query' => 'implant ankara', 'clicks' => (int) round(920 * $f['results_factor']), 'position' => 8.2],
                    ['query' => 'diş implantı fiyat', 'clicks' => (int) round(410 * $f['results_factor']), 'position' => 18.0],
                    ['query' => 'atlas dental', 'clicks' => (int) round(380 * $f['results_factor']), 'position' => 1.4],
                ],
            ],
            'conversions' => [
                'events' => [
                    ['event' => 'generate_lead', 'count' => (int) round(148 * $f['results_factor']), 'share' => 73],
                    ['event' => 'whatsapp_click', 'count' => (int) round(36 * $f['results_factor']), 'share' => 18],
                    ['event' => 'phone_click', 'count' => (int) round(18 * $f['results_factor']), 'share' => 9],
                ],
                'by_landing' => [
                    ['path' => '/implant', 'leads' => (int) round(88 * $f['results_factor']), 'rate' => 1.42],
                    ['path' => '/post-bariatric', 'leads' => (int) round(54 * $f['results_factor']), 'rate' => 1.32],
                    ['path' => '/contact', 'leads' => (int) round(31 * $f['results_factor']), 'rate' => 1.48],
                    ['path' => '/', 'leads' => (int) round(22 * $f['results_factor']), 'rate' => 0.58],
                ],
            ],
            'insights' => [
                [
                    'theme' => 'Performance',
                    'title' => 'Paid landing speed is the conversion bottleneck',
                    'body' => 'Field vitals are healthier than lab on homepage, but /implant lab LCP remains the paid-landing risk.',
                    'action' => 'Prioritize hero media + chat widget on /implant.',
                ],
                [
                    'theme' => 'Content',
                    'title' => 'Informational queries leak to paid search',
                    'body' => 'Implant care draft is unpublished while “implant bakımı” intent appears in paid terms.',
                    'action' => 'Publish and index the care guide.',
                ],
                [
                    'theme' => 'Search',
                    'title' => 'High-impression low-CTR query cluster',
                    'body' => '“diş implantı fiyat” sits at position ~18 with weak CTR versus branded terms.',
                    'action' => 'Review title/meta and content match on /implant.',
                ],
                [
                    'theme' => 'Lifecycle',
                    'title' => 'Hosting renewal window is open',
                    'body' => 'DemoHost renews in 34 days; domain/SSL remain comfortable.',
                    'action' => 'Confirm renewal owner on the hosting workspace.',
                ],
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
        $criticalFindings = collect(self::findings())->whereIn('severity', ['critical', 'high'])->count();
        $openTasks = collect(self::tasksSeed())->where('status', '!=', 'completed')->count();

        return [
            'needs_attention' => [
                [
                    'severity' => 'critical',
                    'title' => 'Critical findings open',
                    'body' => $criticalFindings.' critical/high findings need review (Meta CPL, Google search-term waste).',
                    'evidence' => 'Largest efficiency risks across paid channels.',
                    'why' => 'Unreviewed critical findings delay corrective work.',
                    'source' => 'Findings',
                    'route' => 'demo.findings',
                    'action_label' => 'Review findings',
                ],
                [
                    'severity' => 'high',
                    'title' => 'Overdue / due-soon tasks',
                    'body' => $openTasks.' open tasks — creative replacement due Friday; negatives due tomorrow.',
                    'evidence' => 'Owned work still in flight on Atlas Dental.',
                    'why' => 'Due-soon tasks block the efficiency loop.',
                    'source' => 'Tasks',
                    'route' => 'demo.tasks',
                    'action_label' => 'Open tasks',
                ],
                [
                    'severity' => 'medium',
                    'title' => 'Sync / import attention',
                    'body' => 'Meta historical import 11 / 31 accounts ready; one account needs attention.',
                    'evidence' => 'Partial coverage can hide account-level deterioration.',
                    'why' => 'Incomplete sync weakens confidence in paid diagnostics.',
                    'source' => 'Integrations',
                    'route' => 'demo.integrations.meta',
                    'action_label' => 'Inspect import',
                ],
                [
                    'severity' => 'info',
                    'title' => 'Upcoming renewals',
                    'body' => 'Hosting renewal in 34 days · SSL in 89 days.',
                    'evidence' => 'Lifecycle reminders — no performance blocker today.',
                    'why' => 'Avoid last-minute infra surprises.',
                    'source' => 'Lifecycle',
                    'route' => 'demo.website',
                    'route_params' => ['tab' => 'settings'],
                    'action_label' => 'View lifecycle',
                ],
            ],
            'brand_cards' => [
                [
                    'id' => $brand['id'],
                    'name' => $brand['name'],
                    'urgency' => 1,
                    'health' => $brand['health'],
                    'health_label' => $brand['health_label'],
                    'media_spend' => $brand['summary']['media_spend'],
                    'platform_leads' => $brand['summary']['platform_leads'],
                    'website_leads' => $brand['summary']['website_leads'],
                    'calls_messages' => $brand['summary']['calls_messages'],
                    'open_tasks' => $brand['open_tasks'],
                    'open_findings' => count(self::findings()),
                    'route' => 'demo.brand',
                    'route_params' => ['brand' => self::BRAND_ID],
                    'channels' => [
                        ['label' => 'Meta', 'status' => 'needs_attention', 'status_label' => 'CPL ↑'],
                        ['label' => 'Google Ads', 'status' => 'healthy', 'status_label' => 'Stable'],
                        ['label' => 'Website', 'status' => 'needs_attention', 'status_label' => 'LCP'],
                        ['label' => 'GBP', 'status' => 'healthy', 'status_label' => '4.8★'],
                    ],
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
                    'route_params' => ['tab' => 'settings'],
                ],
                [
                    'label' => 'SSL certificate',
                    'when' => '09 Nov 2026',
                    'detail' => '89 days remaining',
                    'route' => 'demo.website',
                    'route_params' => ['tab' => 'settings'],
                ],
                [
                    'label' => 'Creative replacement task due',
                    'when' => 'Friday',
                    'detail' => 'Replace PB-Video-03',
                    'route' => 'demo.tasks',
                ],
            ],
            'recent_operations' => self::activitySeed(),
            'secondary_counts' => [
                ['label' => 'Open findings', 'value' => count(self::findings()), 'route' => 'demo.findings'],
                ['label' => 'Open tasks', 'value' => $openTasks, 'route' => 'demo.tasks'],
                ['label' => 'Pending recommendations', 'value' => collect(self::recommendationsSeed())->where('status', 'pending')->count(), 'route' => 'demo.recommendations'],
                ['label' => 'Assets', 'value' => count(self::assets()), 'route' => 'demo.assets'],
            ],
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
            'attention' => [
                [
                    'severity' => 'medium',
                    'title' => 'Local pack rank slipped for “implant ankara”',
                    'body' => 'Average map rank 5.4 (was 4.8).',
                    'evidence' => 'External Local Rank Tracking',
                    'why' => 'Primary commercial keyword lost top-3 coverage in NE cells.',
                    'source' => 'Visibility',
                    'route' => 'demo.gbp',
                    'route_params' => ['tab' => 'visibility'],
                    'action_label' => 'Open map',
                ],
                [
                    'severity' => 'medium',
                    'title' => '12 unanswered reviews',
                    'body' => 'Waiting-time theme recurring in recent 3★ reviews.',
                    'evidence' => 'Connected provider reviews',
                    'why' => 'Unanswered friction themes hurt conversion trust.',
                    'source' => 'Reviews',
                    'route' => 'demo.gbp',
                    'route_params' => ['tab' => 'reviews'],
                    'action_label' => 'Open reviews',
                ],
            ],
            'interaction_trend' => self::trendSeries('gbp_interactions', (int) round(14 * $f['results_factor']), 80, 160),
            'keywords' => [
                'implant ankara',
                'diş kliniği ankara',
                'zirkonyum ankara',
                'çankaya diş kliniği',
            ],
            'maps' => [
                'implant ankara' => [
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
                    'provenance' => 'External Local Rank Tracking',
                ],
                'diş kliniği ankara' => [
                    'keyword' => 'diş kliniği ankara',
                    'average_rank' => 8.1,
                    'top3' => 28,
                    'top10' => 72,
                    'previous_average' => 7.9,
                    'grid' => [
                        [6, 5, 4, 5, 7],
                        [8, 6, 4, 5, 8],
                        [10, 7, 5, 6, 9],
                        [14, 11, 8, 9, 13],
                        [19, 16, 12, 14, 20],
                    ],
                    'provenance' => 'External Local Rank Tracking',
                ],
                'zirkonyum ankara' => [
                    'keyword' => 'zirkonyum ankara',
                    'average_rank' => 11.0,
                    'top3' => 12,
                    'top10' => 48,
                    'previous_average' => 12.2,
                    'grid' => [
                        [9, 8, 7, 8, 10],
                        [11, 9, 7, 8, 11],
                        [13, 10, 8, 9, 12],
                        [16, 13, 11, 12, 15],
                        [21, 18, 14, 16, 22],
                    ],
                    'provenance' => 'External Local Rank Tracking',
                ],
                'çankaya diş kliniği' => [
                    'keyword' => 'çankaya diş kliniği',
                    'average_rank' => 3.2,
                    'top3' => 68,
                    'top10' => 96,
                    'previous_average' => 3.5,
                    'grid' => [
                        [2, 1, 1, 2, 3],
                        [3, 2, 1, 2, 3],
                        [4, 3, 2, 2, 4],
                        [6, 4, 3, 3, 5],
                        [9, 7, 5, 6, 8],
                    ],
                    'provenance' => 'External Local Rank Tracking',
                ],
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
                'provenance' => 'External Local Rank Tracking',
            ],
            'reviews' => [
                'distribution' => [5 => 920, 4 => 240, 3 => 70, 2 => 22, 1 => 12],
                'themes_positive' => ['staff', 'cleanliness', 'communication'],
                'themes_negative' => ['waiting time', 'parking'],
                'ai_summary' => 'Patients praise staff and communication. Recurring friction: waiting time and parking near Çankaya clinic.',
                'disclaimer' => 'Demo Mode AI summary — grounded in seeded review themes only. No live model call. AI does not reply to reviews.',
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
            'profile' => [
                ['label' => 'Display name', 'value' => 'Atlas Dental Ankara', 'provenance' => 'Connected provider'],
                ['label' => 'Primary category', 'value' => 'Dental clinic', 'provenance' => 'Connected provider'],
                ['label' => 'Phone', 'value' => '+90 312 000 00 00', 'provenance' => 'Connected provider'],
                ['label' => 'Website', 'value' => 'https://atlasdental.example', 'provenance' => 'Connected provider'],
                ['label' => 'Address', 'value' => 'Çankaya, Ankara', 'provenance' => 'Connected provider'],
                ['label' => 'Hours', 'value' => 'Mon–Sat 09:00–19:00', 'provenance' => 'Connected provider'],
                ['label' => 'Attributes', 'value' => 'Wheelchair accessible · Appointment required', 'provenance' => 'Connected provider'],
            ],
            'competitors' => [
                ['name' => 'Demo Smile Clinic', 'rating' => 4.6, 'reviews' => 890, 'map_rank' => 4.1],
                ['name' => 'Ankara Implant Center (demo)', 'rating' => 4.7, 'reviews' => 1102, 'map_rank' => 3.8],
                ['name' => 'Çankaya Dental Hub (demo)', 'rating' => 4.5, 'reviews' => 640, 'map_rank' => 6.2],
            ],
            'insights' => [
                [
                    'theme' => 'Visibility',
                    'title' => 'NE grid cells lost top-3 coverage',
                    'body' => '“implant ankara” deteriorated from 4.8 → 5.4 average rank, concentrated in north-east cells.',
                ],
                [
                    'theme' => 'Reviews',
                    'title' => 'Waiting-time theme needs an ops reply playbook',
                    'body' => '3★ cluster cites wait times; 12 reviews remain unanswered.',
                ],
                [
                    'theme' => 'Queries',
                    'title' => 'Neighborhood query outperforms commercial head term',
                    'body' => '“çankaya diş kliniği” holds stronger pack ranks than “implant ankara”.',
                ],
            ],
        ];
    }

    public static function analyticsOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'provenance' => 'Connected data source',
            'kpis' => [
                ['label' => 'Users', 'value' => (int) round(18420 * $f['results_factor']), 'format' => 'int', 'delta' => 6.2, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'New Users', 'value' => (int) round(12100 * $f['results_factor']), 'format' => 'int', 'delta' => 4.8, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Sessions', 'value' => (int) round(23840 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'sessions'), 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Engaged Sessions', 'value' => (int) round(14200 * $f['results_factor']), 'format' => 'int', 'delta' => 3.1, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Conversions', 'value' => (int) round(202 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'leads'), 'tone' => 'bad', 'family' => 'result'],
            ],
            'sources' => [
                ['source' => 'Organic Search', 'sessions' => (int) round(9200 * $f['results_factor']), 'engaged' => 62],
                ['source' => 'Paid Social', 'sessions' => (int) round(6100 * $f['results_factor']), 'engaged' => 48],
                ['source' => 'Paid Search', 'sessions' => (int) round(4800 * $f['results_factor']), 'engaged' => 55],
                ['source' => 'Direct', 'sessions' => (int) round(2740 * $f['results_factor']), 'engaged' => 58],
            ],
            'landing_pages' => [
                ['path' => '/implant', 'sessions' => (int) round(6200 * $f['results_factor']), 'engaged_rate' => 54, 'conversions' => (int) round(88 * $f['results_factor'])],
                ['path' => '/post-bariatric', 'sessions' => (int) round(4100 * $f['results_factor']), 'engaged_rate' => 61, 'conversions' => (int) round(54 * $f['results_factor'])],
                ['path' => '/', 'sessions' => (int) round(3800 * $f['results_factor']), 'engaged_rate' => 49, 'conversions' => (int) round(22 * $f['results_factor'])],
                ['path' => '/contact', 'sessions' => (int) round(2100 * $f['results_factor']), 'engaged_rate' => 72, 'conversions' => (int) round(31 * $f['results_factor'])],
            ],
            'engagement' => [
                ['metric' => 'Engagement rate', 'value' => '59.6%'],
                ['metric' => 'Avg engagement time', 'value' => '1m 42s'],
                ['metric' => 'Views / session', 'value' => '2.4'],
                ['metric' => 'Bounce (approx)', 'value' => '40.4%'],
            ],
            'key_events' => [
                ['event' => 'generate_lead', 'count' => (int) round(148 * $f['results_factor'])],
                ['event' => 'whatsapp_click', 'count' => (int) round(36 * $f['results_factor'])],
                ['event' => 'phone_click', 'count' => (int) round(18 * $f['results_factor'])],
                ['event' => 'form_start', 'count' => (int) round(410 * $f['results_factor'])],
            ],
            'devices' => ['mobile' => 68, 'desktop' => 28, 'tablet' => 4],
            'sessions_trend' => self::trendSeries('ga4_sessions', (int) round(14 * $f['results_factor']), 520, 980),
        ];
    }

    public static function searchConsoleOverview(string $preset = 'last_28'): array
    {
        $f = self::periodFactors($preset);

        return [
            'period_label' => $f['label'],
            'provenance' => 'Connected data source',
            'kpis' => [
                ['label' => 'Clicks', 'value' => (int) round(8420 * $f['results_factor']), 'format' => 'int', 'delta' => self::compareDelta($preset, 'clicks'), 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'Impressions', 'value' => (int) round(186000 * $f['results_factor']), 'format' => 'int', 'delta' => 4.2, 'tone' => 'good', 'family' => 'delivery'],
                ['label' => 'CTR', 'value' => 4.5, 'format' => 'pct', 'delta' => -0.3, 'tone' => 'warn', 'family' => 'efficiency'],
                ['label' => 'Average Position', 'value' => 12.4, 'format' => 'float', 'delta' => -1.1, 'tone' => 'warn', 'family' => 'efficiency'],
            ],
            'queries' => [
                ['query' => 'implant ankara', 'clicks' => (int) round(920 * $f['results_factor']), 'impressions' => (int) round(18000 * $f['results_factor']), 'ctr' => 5.1, 'position' => 8.2, 'trend' => 'growing'],
                ['query' => 'diş implantı fiyat', 'clicks' => (int) round(410 * $f['results_factor']), 'impressions' => (int) round(22000 * $f['results_factor']), 'ctr' => 1.9, 'position' => 18.0, 'trend' => 'declining'],
                ['query' => 'atlas dental', 'clicks' => (int) round(380 * $f['results_factor']), 'impressions' => (int) round(2100 * $f['results_factor']), 'ctr' => 18.1, 'position' => 1.4, 'trend' => 'stable'],
                ['query' => 'çankaya diş kliniği', 'clicks' => (int) round(290 * $f['results_factor']), 'impressions' => (int) round(6400 * $f['results_factor']), 'ctr' => 4.5, 'position' => 9.1, 'trend' => 'growing'],
            ],
            'pages' => [
                ['page' => '/implant', 'clicks' => (int) round(2100 * $f['results_factor']), 'impressions' => (int) round(42000 * $f['results_factor']), 'ctr' => 5.0, 'position' => 9.4],
                ['page' => '/', 'clicks' => (int) round(1600 * $f['results_factor']), 'impressions' => (int) round(38000 * $f['results_factor']), 'ctr' => 4.2, 'position' => 11.2],
                ['page' => '/post-bariatric', 'clicks' => (int) round(980 * $f['results_factor']), 'impressions' => (int) round(21000 * $f['results_factor']), 'ctr' => 4.7, 'position' => 10.1],
                ['page' => '/contact', 'clicks' => (int) round(420 * $f['results_factor']), 'impressions' => (int) round(8200 * $f['results_factor']), 'ctr' => 5.1, 'position' => 7.8],
            ],
            'countries' => [
                ['country' => 'Türkiye', 'clicks' => (int) round(7100 * $f['results_factor']), 'impressions' => (int) round(152000 * $f['results_factor'])],
                ['country' => 'Germany', 'clicks' => (int) round(620 * $f['results_factor']), 'impressions' => (int) round(14000 * $f['results_factor'])],
                ['country' => 'United Kingdom', 'clicks' => (int) round(310 * $f['results_factor']), 'impressions' => (int) round(8200 * $f['results_factor'])],
                ['country' => 'Netherlands', 'clicks' => (int) round(180 * $f['results_factor']), 'impressions' => (int) round(4100 * $f['results_factor'])],
            ],
            'devices' => [
                ['device' => 'Mobile', 'clicks' => (int) round(5800 * $f['results_factor']), 'ctr' => 4.1, 'position' => 13.2],
                ['device' => 'Desktop', 'clicks' => (int) round(2400 * $f['results_factor']), 'ctr' => 5.6, 'position' => 10.1],
                ['device' => 'Tablet', 'clicks' => (int) round(220 * $f['results_factor']), 'ctr' => 3.8, 'position' => 14.0],
            ],
            'indexing' => [
                'indexed' => 184,
                'discovered_not_indexed' => 9,
                'crawled_not_indexed' => 4,
                'excluded' => 13,
                'issues' => [
                    ['title' => 'Discovered – currently not indexed', 'count' => 9, 'severity' => 'medium'],
                    ['title' => 'Crawled – currently not indexed', 'count' => 4, 'severity' => 'medium'],
                    ['title' => 'Excluded by noindex', 'count' => 3, 'severity' => 'info'],
                ],
            ],
            'sitemaps' => [
                ['path' => '/sitemap.xml', 'submitted' => '01 Aug 2026', 'discovered' => 197, 'status' => 'Success'],
                ['path' => '/sitemap-blog.xml', 'submitted' => '01 Aug 2026', 'discovered' => 42, 'status' => 'Success'],
            ],
            'url_inspection' => [
                ['url' => 'https://atlasdental.example/implant', 'coverage' => 'Indexed', 'last_crawl' => '10 Aug 2026', 'mobile' => 'Valid'],
                ['url' => 'https://atlasdental.example/blog/implant-bakimi', 'coverage' => 'URL is unknown to Google', 'last_crawl' => '—', 'mobile' => '—'],
                ['url' => 'https://atlasdental.example/team', 'coverage' => 'Indexed', 'last_crawl' => '08 Aug 2026', 'mobile' => 'Valid'],
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
                'recommendation_id' => 'r-gbp-reviews',
                'owner' => 'Clinic ops',
                'priority' => 'medium',
                'due' => 'Waiting on clinic copy',
                'status' => 'blocked',
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
                'recommendation_id' => 'r-map-relevance',
                'owner' => 'Local SEO',
                'priority' => 'medium',
                'due' => 'Last week',
                'status' => 'completed',
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
                    'status' => 'associated_improvement',
                    'label' => 'Associated improvement observed',
                    'before' => 'Avg map rank 5.4',
                    'after' => 'Avg map rank 5.1',
                    'period' => '28 days',
                    'note' => 'Not claiming causality — follow-up data only.',
                ],
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
                'title' => 'Meta Ads — lead cost up 31%',
                'body' => 'Lead cost increased 31% in the last 14 days.',
                'evidence' => 'Main contributor: Post Bariatric — Europe campaign.',
                'why' => 'Largest paid efficiency issue on the brand.',
                'source' => 'Connected provider',
                'route' => 'demo.meta.overview',
                'action_label' => 'Inspect Meta',
            ],
            [
                'severity' => 'medium',
                'asset' => 'Website',
                'issue' => 'Mobile LCP deteriorated to 4.1s on the primary landing page.',
                'title' => 'Website — mobile LCP 4.1s',
                'body' => 'Mobile LCP deteriorated to 4.1s on the primary landing page.',
                'evidence' => '/implant technical check',
                'why' => 'May amplify paid-media inefficiency.',
                'source' => 'Public + Detected',
                'route' => 'demo.website',
                'action_label' => 'Inspect website',
            ],
            [
                'severity' => 'medium',
                'asset' => 'Google Business Profile',
                'issue' => '"implant ankara" visibility weakened in the north-east map grid.',
                'title' => 'GBP — map visibility weakened',
                'body' => '"implant ankara" visibility weakened in the north-east map grid.',
                'evidence' => 'Avg map rank 4.8 → 5.4',
                'why' => 'Local demand keyword for implants.',
                'source' => 'External search intelligence',
                'route' => 'demo.gbp',
                'action_label' => 'Inspect GBP',
            ],
            [
                'severity' => 'info',
                'asset' => 'Domain',
                'issue' => 'Renewal in 221 days.',
                'title' => 'Domain — renewal in 221 days',
                'body' => 'Renewal in 221 days.',
                'evidence' => 'Expiry 21 Mar 2027',
                'why' => 'Lifecycle awareness — no immediate action.',
                'source' => 'Detected',
                'route' => 'demo.website',
                'route_params' => ['tab' => 'settings'],
                'action_label' => 'View lifecycle',
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
            [
                'id' => 'a1',
                'title' => 'Meta data import',
                'status' => 'running',
                'detail' => '11 / 31 accounts ready',
                'when' => 'Now',
                'link' => 'demo.integrations.meta',
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
                'title' => 'Brand AI analysis',
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
                'link' => 'demo.integrations.meta',
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
