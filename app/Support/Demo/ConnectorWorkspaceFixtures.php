<?php

namespace App\Support\Demo;

/**
 * Deterministic Integration Connector workspaces (control plane).
 *
 * Ontology:
 * Integration → Connector → External Resource ↔ Binding ↔ Digital Asset
 * Asset Relationship is separate (e.g. GA4 measures Website).
 *
 * Connector Data = collection health. Digital Asset = intelligence.
 */
final class ConnectorWorkspaceFixtures
{
    public const CONNECTORS = [
        'google-ads',
        'ga4',
        'gsc',
        'gbp',
        'meta-ads',
    ];

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return self::CONNECTORS;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function connector(string $id): ?array
    {
        return match ($id) {
            'google-ads' => self::googleAds(),
            'ga4' => self::ga4(),
            'gsc' => self::gsc(),
            'gbp' => self::gbp(),
            'meta-ads' => self::metaAds(),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function googleAds(): array
    {
        return self::base(
            id: 'google-ads',
            integration: 'google',
            integration_label: 'Google',
            name: 'Google Ads',
            type: 'google_ads',
            connection: 'Healthy',
            freshness: 'Fresh',
            resources: [
                [
                    'id' => 'gads-atlas',
                    'name' => 'Atlas Dental Europe',
                    'external_id' => '123-456-7890',
                    'provider_status' => 'Enabled',
                    'currency' => 'TRY',
                    'timezone' => 'Europe/Istanbul',
                    'state' => 'bound',
                    'state_label' => 'Bound',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                    'asset_name' => 'Atlas Dental — Google Ads',
                    'asset_route' => 'operator.google-ads.overview',
                    'last_collection' => '1h ago',
                    'data_state' => 'Fresh',
                    'match_signal' => 'Possible match · name affinity (not certain)',
                    'recommended' => false,
                ],
                [
                    'id' => 'gads-panorama',
                    'name' => 'Panorama Ankara Ads',
                    'external_id' => '222-333-4444',
                    'provider_status' => 'Enabled',
                    'currency' => 'TRY',
                    'timezone' => 'Europe/Istanbul',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
                [
                    'id' => 'gads-horizon',
                    'name' => 'Horizon Clinic Ads',
                    'external_id' => '555-666-7777',
                    'provider_status' => 'Enabled',
                    'currency' => 'EUR',
                    'timezone' => 'Europe/Berlin',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
                [
                    'id' => 'gads-legacy',
                    'name' => 'Atlas Legacy (disabled)',
                    'external_id' => '111-000-9999',
                    'provider_status' => 'Suspended',
                    'currency' => 'TRY',
                    'timezone' => 'Europe/Istanbul',
                    'state' => 'unavailable',
                    'state_label' => 'Unavailable',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => '12 days ago',
                    'data_state' => 'Unavailable',
                    'match_signal' => null,
                    'recommended' => false,
                ],
            ],
            data: [
                'latest_through' => 'Aug 12',
                'metrics' => [
                    ['label' => 'Campaigns observed', 'value' => '18', 'state' => 'Available'],
                    ['label' => 'Spend metrics', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Conversion metrics', 'value' => 'Partially configured', 'state' => 'Delayed'],
                ],
                'note' => 'Connector Data confirms collection — open the Google Ads Digital Asset for campaign intelligence.',
                'asset_cta' => ['label' => 'Open Google Ads Digital Asset →', 'route' => 'operator.google-ads.overview', 'params' => []],
            ],
            sync: [
                'last_success' => '1h ago',
                'last_attempt' => '1h ago',
                'status' => 'Idle',
                'failure' => null,
                'scope' => 'Account performance aggregates · last 30 days',
                'timezone' => 'Europe/Istanbul',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function ga4(): array
    {
        return self::base(
            id: 'ga4',
            integration: 'google',
            integration_label: 'Google',
            name: 'Google Analytics',
            type: 'ga4',
            connection: 'Healthy',
            freshness: 'Fresh',
            resources: [
                [
                    'id' => 'ga4-atlas',
                    'name' => 'Atlas Dental GA4',
                    'external_id' => '445566778',
                    'stream' => 'atlasdental.example',
                    'stream_label' => 'Website stream',
                    'state' => 'bound',
                    'state_label' => 'Bound',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GA4_ASSET_ID,
                    'asset_name' => 'Atlas Dental — GA4',
                    'asset_route' => 'operator.analytics',
                    'related_website' => 'Atlas Dental Website',
                    'related_website_note' => 'Asset Relationship · GA4 measures Website (not a Binding)',
                    'last_collection' => '1h ago',
                    'data_state' => 'Fresh',
                    'match_signal' => 'Matches Brand Website · atlasdental.example',
                    'recommended' => true,
                ],
                [
                    'id' => 'ga4-panorama',
                    'name' => 'Panorama Ankara GA4',
                    'external_id' => '123456789',
                    'stream' => 'panorama.example',
                    'stream_label' => 'Website stream',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
                [
                    'id' => 'ga4-horizon',
                    'name' => 'Horizon Clinic GA4',
                    'external_id' => '987654321',
                    'stream' => 'horizon.example',
                    'stream_label' => 'Website stream',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
                [
                    'id' => 'ga4-conflict',
                    'name' => 'Atlas Dental (duplicate property)',
                    'external_id' => '445566779',
                    'stream' => 'atlasdental.example',
                    'stream_label' => 'Website stream',
                    'state' => 'conflict',
                    'state_label' => 'Conflict',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GA4_ASSET_ID,
                    'asset_name' => 'Atlas Dental — GA4',
                    'asset_route' => 'operator.analytics',
                    'last_collection' => '3d ago',
                    'data_state' => 'Stale',
                    'match_signal' => 'Same Brand Website stream already bound — operator review required',
                    'recommended' => false,
                ],
            ],
            data: [
                'latest_through' => 'Aug 12',
                'metrics' => [
                    ['label' => 'Users', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Sessions', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Events', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Rows / aggregates', 'value' => 'Collected successfully', 'state' => 'Available'],
                ],
                'note' => 'Connector Data answers “did we collect it?” — open the GA4 Digital Asset for analysis.',
                'asset_cta' => ['label' => 'Open Google Analytics Digital Asset →', 'route' => 'operator.analytics', 'params' => []],
            ],
            sync: [
                'last_success' => '1h ago',
                'last_attempt' => '1h ago',
                'status' => 'Idle',
                'failure' => null,
                'scope' => 'Property aggregates · reporting timezone Europe/Istanbul',
                'timezone' => 'Europe/Istanbul',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function gsc(): array
    {
        return self::base(
            id: 'gsc',
            integration: 'google',
            integration_label: 'Google',
            name: 'Search Console',
            type: 'gsc',
            connection: 'Healthy',
            freshness: 'Fresh',
            resources: [
                [
                    'id' => 'gsc-atlas',
                    'name' => 'sc-domain:atlasdental.example',
                    'external_id' => 'sc-domain:atlasdental.example',
                    'property_type' => 'Domain property',
                    'state' => 'bound',
                    'state_label' => 'Bound',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GSC_ASSET_ID,
                    'asset_name' => 'Atlas Dental — Search Console',
                    'asset_route' => 'operator.search-console',
                    'last_collection' => '2h ago',
                    'data_state' => 'Fresh',
                    'match_signal' => 'Matches Brand Website · atlasdental.example',
                    'recommended' => true,
                ],
                [
                    'id' => 'gsc-panorama',
                    'name' => 'panorama.example',
                    'external_id' => 'sc-domain:panorama.example',
                    'property_type' => 'Domain property',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
                [
                    'id' => 'gsc-horizon',
                    'name' => 'horizon.example',
                    'external_id' => 'sc-domain:horizon.example',
                    'property_type' => 'Domain property',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
            ],
            data: [
                'latest_through' => 'Aug 12',
                'metrics' => [
                    ['label' => 'Search appearance rows', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Query aggregates', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'URL inspection samples', 'value' => 'Available', 'state' => 'Available'],
                ],
                'note' => 'No Search Console intelligence here — open the Digital Asset workspace.',
                'asset_cta' => ['label' => 'Open Search Console Digital Asset →', 'route' => 'operator.search-console', 'params' => []],
            ],
            sync: [
                'last_success' => '2h ago',
                'last_attempt' => '2h ago',
                'status' => 'Idle',
                'failure' => null,
                'scope' => 'Search analytics · sitemap status',
                'timezone' => 'UTC',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function gbp(): array
    {
        return self::base(
            id: 'gbp',
            integration: 'google',
            integration_label: 'Google',
            name: 'Google Business Profile',
            type: 'gbp',
            connection: 'Healthy',
            freshness: 'Fresh',
            resources: [
                [
                    'id' => 'gbp-atlas',
                    'name' => 'Atlas Dental Ankara',
                    'external_id' => 'locations/atlas-cankaya',
                    'address' => 'Çankaya, Ankara',
                    'website' => 'atlasdental.example',
                    'state' => 'bound',
                    'state_label' => 'Bound',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GBP_ASSET_ID,
                    'asset_name' => 'Atlas Dental Ankara',
                    'asset_route' => 'operator.gbp',
                    'last_collection' => '2h ago',
                    'data_state' => 'Fresh',
                    'match_signal' => 'Matches Brand Website · atlasdental.example',
                    'recommended' => true,
                ],
                [
                    'id' => 'gbp-panorama',
                    'name' => 'Panorama Dental',
                    'external_id' => 'locations/panorama-1',
                    'address' => 'Keçiören, Ankara',
                    'website' => 'panorama.example',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
            ],
            data: [
                'latest_through' => 'Aug 12',
                'metrics' => [
                    ['label' => 'Profile fields', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Reviews snapshot', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Performance aggregates', 'value' => 'Available', 'state' => 'Available'],
                ],
                'note' => 'No full GBP profile report here — open the GBP Digital Asset.',
                'asset_cta' => ['label' => 'Open Google Business Profile Digital Asset →', 'route' => 'operator.gbp', 'params' => []],
            ],
            sync: [
                'last_success' => '2h ago',
                'last_attempt' => '2h ago',
                'status' => 'Idle',
                'failure' => null,
                'scope' => 'Location profile · reviews · performance',
                'timezone' => 'Europe/Istanbul',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function metaAds(): array
    {
        return self::base(
            id: 'meta-ads',
            integration: 'meta',
            integration_label: 'Meta',
            name: 'Meta Ads',
            type: 'meta_ads',
            connection: 'Healthy',
            freshness: 'Fresh',
            resources: [
                [
                    'id' => 'meta-atlas',
                    'name' => 'Atlas Dental — Meta',
                    'external_id' => 'act_100200300',
                    'currency' => 'TRY',
                    'timezone' => 'Europe/Istanbul',
                    'state' => 'bound',
                    'state_label' => 'Bound',
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand_name' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::META_ASSET_ID,
                    'asset_name' => 'Atlas Dental — Meta',
                    'asset_route' => 'operator.meta.overview',
                    'last_collection' => '45 min ago',
                    'data_state' => 'Fresh',
                    'match_signal' => 'Possible match · Brand name affinity (not certain)',
                    'recommended' => false,
                ],
                [
                    'id' => 'meta-panorama',
                    'name' => 'Panorama Ads Account',
                    'external_id' => 'act_900800700',
                    'currency' => 'TRY',
                    'timezone' => 'Europe/Istanbul',
                    'state' => 'available',
                    'state_label' => 'Available',
                    'brand_id' => null,
                    'brand_name' => null,
                    'asset_id' => null,
                    'asset_name' => null,
                    'asset_route' => null,
                    'last_collection' => 'Not collected yet',
                    'data_state' => 'Not collected yet',
                    'match_signal' => null,
                    'recommended' => false,
                ],
            ],
            data: [
                'latest_through' => 'Aug 12',
                'metrics' => [
                    ['label' => 'Campaigns observed', 'value' => '12', 'state' => 'Available'],
                    ['label' => 'Spend metrics', 'value' => 'Available', 'state' => 'Available'],
                    ['label' => 'Creative inventory', 'value' => 'Available', 'state' => 'Available'],
                ],
                'note' => 'No creative analytics here — open the Meta Ads Digital Asset.',
                'asset_cta' => ['label' => 'Open Meta Ads Digital Asset →', 'route' => 'operator.meta.overview', 'params' => []],
            ],
            sync: [
                'last_success' => '45 min ago',
                'last_attempt' => '45 min ago',
                'status' => 'Idle',
                'failure' => '1 account needs permission review (demo)',
                'scope' => 'Ad account performance · creatives metadata',
                'timezone' => 'Europe/Istanbul',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $resources
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $sync
     * @return array<string, mixed>
     */
    private static function base(
        string $id,
        string $integration,
        string $integration_label,
        string $name,
        string $type,
        string $connection,
        string $freshness,
        array $resources,
        array $data,
        array $sync,
    ): array {
        $bound = collect($resources)->where('state', 'bound')->count();
        $available = collect($resources)->where('state', 'available')->count();
        $conflict = collect($resources)->where('state', 'conflict')->count();
        $unavailable = collect($resources)->where('state', 'unavailable')->count();

        $activity = [
            ['when' => '1h ago', 'event' => 'Collection completed', 'detail' => $name.' · aggregates refreshed', 'actor' => 'System', 'kind' => 'collection_completed'],
            ['when' => 'Yesterday', 'event' => 'Resource bound', 'detail' => collect($resources)->firstWhere('state', 'bound')['name'] ?? $name, 'actor' => 'Ayşe Demir', 'kind' => 'resource_bound'],
            ['when' => '2 days ago', 'event' => 'Resource discovered', 'detail' => collect($resources)->firstWhere('state', 'available')['name'] ?? 'Available resource', 'actor' => 'System', 'kind' => 'resource_discovered'],
            ['when' => '3 days ago', 'event' => 'Authorized', 'detail' => $integration_label.' Integration', 'actor' => 'System', 'kind' => 'authorized'],
        ];

        return [
            'id' => $id,
            'integration' => $integration,
            'integration_label' => $integration_label,
            'integration_route' => $integration === 'google' ? 'operator.integrations.google' : 'operator.integrations.meta',
            'name' => $name,
            'type' => $type,
            'connection' => $connection,
            'freshness' => $freshness,
            'resources_count' => count($resources),
            'bound' => $bound,
            'available' => $available,
            'conflict' => $conflict,
            'unavailable' => $unavailable,
            'latest_collection' => $sync['last_success'],
            'resources' => $resources,
            'bindings' => collect($resources)
                ->where('state', 'bound')
                ->map(fn (array $r): array => [
                    'resource_id' => $r['id'],
                    'resource_name' => $r['name'],
                    'external_id' => $r['external_id'],
                    'binding_label' => $integration_label.' Integration',
                    'asset_id' => $r['asset_id'],
                    'asset_name' => $r['asset_name'],
                    'asset_route' => $r['asset_route'],
                    'brand_id' => $r['brand_id'],
                    'brand_name' => $r['brand_name'],
                    'related_website' => $r['related_website'] ?? null,
                    'related_website_note' => $r['related_website_note'] ?? null,
                ])
                ->values()
                ->all(),
            'data' => $data,
            'sync' => $sync,
            'activity' => $activity,
            'existing_assets_for_brand' => self::existingAssetsForType($type),
            'ontology_note' => 'Binding links External Resource ↔ Digital Asset. Asset Relationships (e.g. measures Website) are separate.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function existingAssetsForType(string $type): array
    {
        return collect(DemoCatalog::assets())
            ->filter(fn (array $a): bool => ($a['type'] ?? '') === $type && ($a['brand_id'] ?? '') === DemoCatalog::BRAND_ID)
            ->map(fn (array $a): array => [
                'id' => $a['id'],
                'name' => $a['name'],
                'brand_id' => $a['brand_id'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function websiteInfrastructure(): array
    {
        $domain = DemoCatalog::domainOverview();
        $hosting = DemoCatalog::hostingOverview();

        return [
            'subtitle' => 'Domain, DNS, hosting, CDN, SSL and CMS belong to the Website Digital Asset — not standalone assets.',
            'attention' => [
                ['severity' => 'Medium', 'title' => 'Hosting renewal due in '.$hosting['days_remaining'].' days', 'detail' => $hosting['provider'].' · '.$hosting['plan']],
                ['severity' => 'Info', 'title' => 'SSL valid · '.$domain['ssl']['days_remaining'].' days remaining', 'detail' => $domain['ssl']['issuer']],
            ],
            'domain' => [
                'hostname' => $domain['domain'],
                'registrar' => $domain['registrar'],
                'registered_at' => $domain['registered_at'],
                'expires_at' => $domain['expires_at'],
                'auto_renew' => $domain['auto_renew'],
                'status' => $domain['status'],
                'provenance' => $domain['provenance'],
            ],
            'dns' => [
                'state' => $domain['dns']['health'] === 'healthy' ? 'Healthy' : 'Needs review',
                'nameservers' => $domain['dns']['nameservers'],
                'records' => $domain['dns']['records'],
            ],
            'hosting' => [
                'provider' => $hosting['provider'],
                'platform' => $hosting['plan'],
                'region' => $hosting['region'],
                'environment' => $hosting['environment'],
                'renewal_at' => $hosting['renewal_at'],
                'provenance' => $hosting['provenance'],
            ],
            'cdn' => [
                'provider' => 'Not detected',
                'state' => 'Unavailable',
                'note' => 'No CDN provider integration in this Demo — missing ≠ zero.',
            ],
            'ssl' => [
                'https' => 'Enabled',
                'issuer' => $domain['ssl']['issuer'],
                'expires_at' => $domain['ssl']['expires_at'],
                'days_remaining' => $domain['ssl']['days_remaining'],
                'grade' => $domain['ssl']['grade'],
                'san' => $domain['ssl']['san'],
                'provenance' => $domain['ssl']['provenance'],
            ],
            'cms' => [
                'name' => 'WordPress',
                'version' => 'Observed in Website Demo',
                'provenance' => 'Website observation',
            ],
            'findings' => [
                ['title' => 'Hosting renewal approaching', 'severity' => 'Medium', 'state' => 'Open'],
            ],
            'legacy_note' => 'Legacy Domain/Hosting Digital Asset records (if any) are preserved as deprecated infrastructure context — not offered for new creation.',
        ];
    }
}
