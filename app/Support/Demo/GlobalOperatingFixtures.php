<?php

namespace App\Support\Demo;

use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use Illuminate\Support\Collection;

/**
 * Deterministic presenter for the global agency operating layer (site root).
 *
 * Reuses DemoCatalog portfolio identity. Does not expand live providers
 * or persist operator-database entities.
 */
final class GlobalOperatingFixtures
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function attentionItems(string $mode = 'agency'): array
    {
        $items = [
            [
                'severity' => 'critical',
                'title' => 'Lead measurement requires investigation',
                'body' => '36h without primary lead signal · 3 campaigns affected',
                'evidence' => 'Atlas Dental · Google Ads',
                'why' => 'Paid acquisition cannot be trusted without the primary conversion signal.',
                'source' => 'Finding',
                'asset_type' => 'google_ads',
                'route' => 'operator.findings',
                'route_params' => [],
                'action_label' => 'Review',
            ],
            [
                'severity' => 'high',
                'title' => 'Overdue and blocked tasks',
                'body' => '1 overdue · 1 blocked across Atlas Dental',
                'evidence' => 'Oldest overdue · map relevance follow-up window',
                'why' => 'Committed work is stalling verification.',
                'source' => 'Tasks',
                'route' => 'operator.tasks',
                'action_label' => 'Open Tasks',
            ],
            [
                'severity' => 'high',
                'title' => 'Google Integration needs attention',
                'body' => '14 dependent Digital Assets · reauthorization recommended',
                'evidence' => 'Authorization refresh window approaching (demo)',
                'why' => 'Provider access underpins Ads, Analytics, Search Console, and GBP.',
                'source' => 'Integrations',
                'route' => 'operator.integrations.google',
                'action_label' => 'Inspect Integration',
            ],
            [
                'severity' => 'medium',
                'title' => 'Local visibility declined',
                'body' => 'Implant · geographic coverage softened in NE grid',
                'evidence' => 'Atlas Dental · Çankaya',
                'why' => 'Primary local demand keyword for implant acquisition.',
                'source' => 'Finding',
                'asset_type' => 'gbp',
                'route' => 'operator.gbp',
                'action_label' => 'Inspect',
            ],
            [
                'severity' => 'medium',
                'title' => 'Meta CPL deteriorated',
                'body' => 'Post Bariatric — Europe efficiency drag',
                'evidence' => 'CPL ₺482 → ₺691',
                'why' => 'Largest paid-efficiency risk on the brand.',
                'source' => 'Finding',
                'asset_type' => 'meta_ads',
                'route' => 'operator.findings',
                'action_label' => 'Review',
            ],
        ];

        if ($mode === 'my_work') {
            return array_values(array_filter(
                $items,
                static fn (array $item): bool => in_array($item['source'], ['Finding', 'Tasks'], true)
                    || str_contains((string) $item['title'], 'Lead measurement')
                    || str_contains((string) $item['title'], 'Overdue')
                    || str_contains((string) $item['title'], 'Meta CPL')
                    || str_contains((string) $item['title'], 'Local visibility'),
            ));
        }

        return $items;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string, list<array<string, mixed>>>
     */
    public static function myWorkQueue($tasks): array
    {
        $open = $tasks->whereIn('status', ['open', 'in_progress', 'blocked']);

        return [
            'due_today' => $open->whereIn('due', ['Tomorrow', 'Friday', 'Today'])->take(3)->values()->all(),
            'overdue' => $open->whereIn('due', ['Last week', 'Yesterday', 'Overdue'])->take(3)->values()->all(),
            'blocked' => $open->where('status', 'blocked')->take(3)->values()->all(),
            'awaiting_follow_up' => $tasks->where('status', 'completed')->filter(
                static fn (array $t): bool => ($t['outcome']['status'] ?? null) !== null
            )->take(3)->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function integrationAttention(): array
    {
        return [
            [
                'id' => 'google',
                'name' => 'Google',
                'state' => 'needs_attention',
                'state_label' => 'Needs attention',
                'detail' => 'Reauthorization recommended · 14 dependent assets',
                'route' => 'operator.integrations.google',
            ],
            [
                'id' => 'meta',
                'name' => 'Meta',
                'state' => 'connected',
                'state_label' => 'Healthy',
                'detail' => '1 import account needs permission review',
                'route' => 'operator.integrations.meta',
            ],
            [
                'id' => 'dataforseo',
                'name' => 'DataForSEO',
                'state' => 'configuration_incomplete',
                'state_label' => 'Quota / configuration review',
                'detail' => 'Demo search intelligence configured',
                'route' => 'operator.integrations',
            ],
        ];
    }

    /**
     * @return array{running: list<array<string, mixed>>, failed: list<array<string, mixed>>, queued: list<array<string, mixed>>}
     */
    public static function runningFailedOps(): array
    {
        return [
            'running' => [
                ['title' => 'Meta data import', 'detail' => 'Atlas Dental · Meta Ads', 'when' => 'Now'],
            ],
            'failed' => [
                ['title' => 'Hosting probe', 'detail' => 'DemoHost timeout (demo)', 'when' => '4 days ago'],
            ],
            'queued' => [
                ['title' => 'GBP analysis', 'detail' => 'Atlas Dental · Çankaya', 'when' => 'Queued'],
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
                'title' => 'Canonical alignment',
                'scope' => 'Atlas Dental · Website',
                'outcome' => 'Improvement observed',
                'tone' => 'good',
                'asset_type' => 'website',
            ],
            [
                'title' => 'Local relevance audit',
                'scope' => 'Atlas Dental · Google Business Profile',
                'outcome' => 'Improvement observed',
                'tone' => 'good',
                'asset_type' => 'gbp',
            ],
            [
                'title' => 'Creative refresh',
                'scope' => 'Atlas Dental · Meta Ads',
                'outcome' => 'Insufficient evidence',
                'tone' => 'neutral',
                'asset_type' => 'meta_ads',
            ],
            [
                'title' => 'Search intent review',
                'scope' => 'Atlas Dental · Google Ads',
                'outcome' => 'Awaiting follow-up',
                'tone' => 'warn',
                'asset_type' => 'google_ads',
            ],
        ];
    }

    /**
     * Enrich Digital Asset rows for the Digital Estate Directory.
     *
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public static function enrichAsset(array $asset): array
    {
        $type = (string) ($asset['type'] ?? '');
        $health = (string) ($asset['health'] ?? 'healthy');

        $operational = match ($type) {
            'domain', 'hosting' => 'active',
            default => 'active',
        };

        $connection = match ((string) ($asset['connection'] ?? '')) {
            'connected', 'public_plus_detected' => 'connected',
            'detected' => 'detected',
            'manual' => 'manual',
            default => 'not_connected',
        };

        $dataState = match (true) {
            $type === 'domain' || $type === 'hosting' => 'not_applicable',
            $health === 'needs_attention' && in_array($type, ['ga4', 'gsc', 'meta_ads'], true) => 'stale',
            $connection === 'manual' => 'unavailable',
            default => 'fresh',
        };

        $responsible = match ($type) {
            'meta_ads', 'google_ads' => ['u-ayse'],
            'website', 'ga4', 'gsc' => ['u-selin'],
            'gbp' => ['u-mert'],
            default => ['u-can'],
        };

        $team = collect(DemoCatalog::teamMembers())->keyBy('id');
        $owners = array_values(array_filter(array_map(
            static fn (string $id): ?array => $team->get($id),
            $responsible,
        )));

        $merged = array_merge($asset, [
            'customer_id' => DemoCatalog::CUSTOMER_ID,
            'customer_name' => DemoCatalog::customer()['name'],
            'brand_name' => DemoCatalog::brand()['name'],
            'operational_status' => $operational,
            'operational_status_label' => ucfirst($operational),
            'connection_state' => $connection,
            'connection_state_label' => match ($connection) {
                'connected' => 'Connected',
                'detected' => 'Detected',
                'manual' => 'Manual',
                default => 'Not connected',
            },
            'data_state' => $dataState,
            'data_state_label' => match ($dataState) {
                'fresh' => 'Fresh',
                'stale' => 'Stale',
                'unavailable' => 'Unavailable',
                default => 'Not applicable',
            },
            'responsible_user_ids' => $responsible,
            'responsible_users' => $owners,
            'attention_priority' => match ($health) {
                'needs_attention' => 'high',
                'warning' => 'medium',
                default => ((int) ($asset['open_findings'] ?? 0) > 0 ? 'medium' : 'none'),
            },
            'last_meaningful_activity' => $asset['last_update'] ?? '—',
        ]);

        return $merged;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function integrationsHub(): array
    {
        return [
            [
                'group' => 'Platforms & Data',
                'providers' => [
                    [
                        'id' => 'google',
                        'name' => 'Google',
                        'logo_type' => 'google_ads',
                        'state' => 'needs_attention',
                        'state_label' => 'Needs attention',
                        'resources_discovered' => 23,
                        'bound' => 14,
                        'available' => 4,
                        'last_check' => '22 min ago',
                        'dependent_assets' => 14,
                        'route' => 'operator.integrations.google',
                        'manage_label' => 'Manage',
                    ],
                    [
                        'id' => 'meta',
                        'name' => 'Meta',
                        'logo_type' => 'meta_ads',
                        'state' => 'connected',
                        'state_label' => 'Connected',
                        'resources_discovered' => 31,
                        'bound' => 2,
                        'available' => 5,
                        'last_check' => '2 hours ago',
                        'dependent_assets' => 2,
                        'route' => 'operator.integrations.meta',
                        'manage_label' => 'Manage',
                    ],
                    [
                        'id' => 'dataforseo',
                        'name' => 'DataForSEO',
                        'logo_type' => 'gsc',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'route' => 'operator.integrations.dataforseo',
                        'manage_label' => 'Configure',
                    ],
                    [
                        'id' => 'bing',
                        'name' => 'Bing Webmaster',
                        'logo_type' => 'website',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'route' => 'operator.integrations.bing',
                        'manage_label' => 'Configure',
                    ],
                ],
            ],
            [
                'group' => 'Site Connectors',
                'id' => 'site_connectors',
                'providers' => [
                    [
                        'id' => 'wordpress',
                        'name' => 'WordPress',
                        'logo_type' => 'website',
                        'state' => 'connected',
                        'state_label' => 'Demo available',
                        'resources_discovered' => 2,
                        'bound' => 1,
                        'available' => 1,
                        'last_check' => '2 hours ago',
                        'dependent_assets' => 1,
                        'note' => 'Installable site package catalog (demo ZIP — not production).',
                        'route' => 'operator.integrations.site-connector',
                        'route_params' => ['connector' => 'wordpress'],
                        'manage_label' => 'Open catalog',
                    ],
                ],
            ],
            [
                'group' => 'Intelligence Providers',
                'providers' => [
                    [
                        'id' => 'openai',
                        'name' => 'OpenAI',
                        'logo_type' => 'website',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'note' => 'Provider availability ≠ AI Recommendations enabled everywhere.',
                        'route' => 'operator.integrations.ai',
                        'route_params' => ['provider' => 'openai'],
                        'manage_label' => 'Configure',
                    ],
                    [
                        'id' => 'anthropic',
                        'name' => 'Anthropic',
                        'logo_type' => 'website',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'note' => 'Optional intelligence provider.',
                        'route' => 'operator.integrations.ai',
                        'route_params' => ['provider' => 'anthropic'],
                        'manage_label' => 'Configure',
                    ],
                    [
                        'id' => 'gemini',
                        'name' => 'Gemini',
                        'logo_type' => 'website',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'note' => 'Google AI provider. Credentials are configured here, not under Google OAuth.',
                        'route' => 'operator.integrations.ai',
                        'route_params' => ['provider' => 'gemini'],
                        'manage_label' => 'Configure',
                    ],
                    [
                        'id' => 'groq',
                        'name' => 'Groq',
                        'logo_type' => 'website',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'note' => 'Ücretsiz / düşük maliyetli açık modeller; müşteri verisi içeren işlere atanmaz.',
                        'route' => 'operator.integrations.ai',
                        'route_params' => ['provider' => 'groq'],
                        'manage_label' => 'Configure',
                    ],
                    [
                        'id' => 'openrouter',
                        'name' => 'OpenRouter',
                        'logo_type' => 'website',
                        'state' => 'not_configured',
                        'state_label' => 'Not configured',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'note' => 'Birçok modele tek anahtarla erişim; müşteri verisi içeren işlere atanmaz.',
                        'route' => 'operator.integrations.ai',
                        'route_params' => ['provider' => 'openrouter'],
                        'manage_label' => 'Configure',
                    ],
                ],
            ],
            [
                'group' => 'Messaging',
                'id' => 'messaging',
                'providers' => [
                ],
            ],
        ];
    }

    /**
     * Legacy Demo fixture for Google Integration narrative content.
     *
     * Frozen `/app/integrations` Google surfaces must use
     * {@see GoogleIntegrationReadModel}
     * instead of this method — Demo counts must not be presented as real state.
     *
     * @return array<string, mixed>
     */
    public static function googleIntegration(): array
    {
        // Bound counts align with disconnect impact / hub card (14 dependent Digital Assets).
        $resourceGroups = [
            [
                'type' => 'google_ads',
                'label' => 'Google Ads',
                'accounts' => 4,
                'bound' => 2,
                'available' => 0,
                'connector' => 'google-ads',
            ],
            [
                'type' => 'ga4',
                'label' => 'Google Analytics',
                'accounts' => 6,
                'bound' => 4,
                'available' => 2,
                'connector' => 'ga4',
            ],
            [
                'type' => 'gsc',
                'label' => 'Search Console',
                'accounts' => 8,
                'bound' => 3,
                'available' => 2,
                'connector' => 'gsc',
            ],
            [
                'type' => 'gbp',
                'label' => 'Google Business Profile',
                'accounts' => 5,
                'bound' => 5,
                'available' => 0,
                'connector' => 'gbp',
            ],
        ];

        $bound = array_sum(array_column($resourceGroups, 'bound'));
        $available = array_sum(array_column($resourceGroups, 'available'));
        $discovered = array_sum(array_column($resourceGroups, 'accounts'));

        return [
            'id' => 'google',
            'name' => 'Google',
            'state' => 'needs_attention',
            'state_label' => 'Needs attention',
            'last_check' => '22 min ago',
            'resources_discovered' => $discovered,
            'bound' => $bound,
            'available' => $available,
            'dependent_assets' => $bound,
            'resource_groups' => $resourceGroups,
            'unbound_resources' => [
                [
                    'id' => 'ga4-panorama',
                    'type' => 'ga4',
                    'type_label' => 'Google Analytics Property',
                    'name' => 'Panorama Ankara GA4',
                    'external_id' => '123456789',
                    'status' => 'available',
                    'status_label' => 'Available · Not bound to a Digital Asset',
                ],
                [
                    'id' => 'ga4-horizon',
                    'type' => 'ga4',
                    'type_label' => 'Google Analytics Property',
                    'name' => 'Horizon Clinic GA4',
                    'external_id' => '987654321',
                    'status' => 'available',
                    'status_label' => 'Available · Not bound to a Digital Asset',
                ],
                [
                    'id' => 'gsc-panorama',
                    'type' => 'gsc',
                    'type_label' => 'Search Console Property',
                    'name' => 'panorama.example',
                    'external_id' => 'sc-domain:panorama.example',
                    'status' => 'available',
                    'status_label' => 'Available · Not bound to a Digital Asset',
                ],
                [
                    'id' => 'gsc-horizon',
                    'type' => 'gsc',
                    'type_label' => 'Search Console Property',
                    'name' => 'horizon.example',
                    'external_id' => 'sc-domain:horizon.example',
                    'status' => 'available',
                    'status_label' => 'Available · Not bound to a Digital Asset',
                ],
            ],
            'bindings' => [
                [
                    'resource' => 'GA4 property 445566778',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental — GA4',
                    'asset_id' => DemoCatalog::GA4_ASSET_ID,
                    'route' => 'operator.analytics',
                ],
                [
                    'resource' => 'GSC sc-domain:atlasdental.example',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental — Search Console',
                    'asset_id' => DemoCatalog::GSC_ASSET_ID,
                    'route' => 'operator.search-console',
                ],
                [
                    'resource' => 'Google Ads customer 123-456-7890',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental — Google Ads',
                    'asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                    'route' => 'operator.google-ads.overview',
                ],
                [
                    'resource' => 'GBP location Çankaya',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GBP_ASSET_ID,
                    'route' => 'operator.gbp',
                ],
            ],
            'disconnect_impact' => [
                'Google Analytics assets' => 4,
                'Search Console assets' => 3,
                'Google Ads assets' => 2,
                'GBP assets' => 5,
                'Total dependent Digital Assets' => 14,
            ],
            'activity' => [
                ['when' => '22 min ago', 'event' => 'Authorization refresh recommended', 'actor' => 'System', 'status' => 'needs_attention'],
                ['when' => '2 hours ago', 'event' => 'Resource discovered — Panorama Ankara GA4', 'actor' => 'System', 'status' => 'success'],
                ['when' => 'Yesterday', 'event' => 'Resource bound — Atlas Dental GA4', 'actor' => 'Ayşe Demir', 'status' => 'success'],
                ['when' => '3 days ago', 'event' => 'Data collection failed due to provider auth (demo)', 'actor' => 'System', 'status' => 'failed'],
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string}>
     */
    public static function settingsSections(): array
    {
        // One level (yakup 2026-10-20): Ayarlar' top tabs are the only menu. Ekip is Kullanıcılar, AI is AI işlemleri,
        // Operasyon is Sistem; Bildirimler and Gelişmiş were sample rows with nothing to set. Old ?section= links open Genel.
        return [
            ['id' => 'general', 'label' => __('operator.settings_ia.general'), 'description' => __('operator.settings_ia.general_help')],
        ];
    }
}
