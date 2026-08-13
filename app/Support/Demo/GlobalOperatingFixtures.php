<?php

namespace App\Support\Demo;

/**
 * Deterministic presenter for the global agency operating layer (/app).
 *
 * Reuses DemoCatalog portfolio identity. Does not expand live providers
 * or persist operator-database entities.
 */
final class GlobalOperatingFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function dashboard(string $mode = 'my_work'): array
    {
        $mode = in_array($mode, ['my_work', 'agency'], true) ? $mode : 'my_work';
        $tasks = collect(DemoState::all()['tasks'] ?? DemoCatalog::tasksSeed());
        $recs = collect(DemoState::all()['recommendations'] ?? DemoCatalog::recommendationsSeed());
        $findings = collect(DemoCatalog::findings());
        $criticalHigh = $findings->whereIn('severity', ['critical', 'high'])->where('status', 'open')->count();
        $dueToday = $tasks->whereIn('status', ['open', 'in_progress'])->whereIn('due', ['Tomorrow', 'Friday', 'Today'])->count();
        $overdueCount = $tasks->filter(static function (array $task): bool {
            return ($task['status'] ?? '') !== 'completed'
                && in_array($task['due'] ?? '', ['Last week', 'Yesterday', 'Overdue'], true);
        })->count();
        $awaitingDecision = $recs->whereIn('status', ['pending', 'awaiting_decision'])->count();
        $brand = DemoCatalog::brand();
        $customer = DemoCatalog::customer();

        return [
            'mode' => $mode,
            'greeting' => self::greeting(),
            'date_label' => now()->timezone(config('app.timezone'))->format('l, F j'),
            'subtitle' => 'Here is what needs attention across your portfolio.',
            'glance' => [
                ['label' => 'Open critical/high Findings', 'value' => $criticalHigh, 'route' => 'demo.findings', 'tone' => 'error'],
                ['label' => 'Tasks due today', 'value' => max($dueToday, 2), 'route' => 'demo.tasks', 'tone' => 'warning'],
                ['label' => 'Overdue tasks', 'value' => max($overdueCount, 1), 'route' => 'demo.tasks', 'tone' => 'error'],
                ['label' => 'Recommendations awaiting decision', 'value' => $awaitingDecision, 'route' => 'demo.recommendations', 'tone' => 'info'],
            ],
            'needs_attention' => self::attentionItems($mode),
            'my_work' => self::myWorkQueue($tasks),
            'portfolio_attention' => [
                [
                    'brand' => $brand['name'],
                    'customer' => $customer['name'],
                    'high_findings' => $findings->whereIn('severity', ['critical', 'high'])->count(),
                    'open_tasks' => $tasks->where('status', '!=', 'completed')->count(),
                    'awaiting_decision' => $awaitingDecision,
                    'asset_types' => ['website', 'google_ads', 'ga4', 'gsc', 'gbp', 'meta_ads'],
                    'route' => 'demo.brand',
                    'route_params' => ['brand' => DemoCatalog::BRAND_ID],
                ],
            ],
            'integrations' => self::integrationAttention(),
            'operations' => self::runningFailedOps(),
            'recent_outcomes' => self::recentOutcomes(),
            // Backward-compatible keys for older tests/partials
            'brand_cards' => [],
            'movements' => [],
            'upcoming' => [],
            'recent_operations' => DemoCatalog::activitySeed(),
            'secondary_counts' => [],
        ];
    }

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
                'route' => 'demo.findings',
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
                'route' => 'demo.tasks',
                'action_label' => 'Open Tasks',
            ],
            [
                'severity' => 'high',
                'title' => 'Google Integration needs attention',
                'body' => '14 dependent Digital Assets · reauthorization recommended',
                'evidence' => 'Authorization refresh window approaching (demo)',
                'why' => 'Provider access underpins Ads, Analytics, Search Console, and GBP.',
                'source' => 'Integrations',
                'route' => 'demo.integrations.google',
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
                'route' => 'demo.gbp',
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
                'route' => 'demo.findings',
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
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $tasks
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
                'route' => 'demo.integrations.google',
            ],
            [
                'id' => 'meta',
                'name' => 'Meta',
                'state' => 'connected',
                'state_label' => 'Healthy',
                'detail' => '1 import account needs permission review',
                'route' => 'demo.integrations.meta',
            ],
            [
                'id' => 'dataforseo',
                'name' => 'DataForSEO',
                'state' => 'configuration_incomplete',
                'state_label' => 'Quota / configuration review',
                'detail' => 'Demo search intelligence configured',
                'route' => 'demo.integrations',
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

        return array_merge($asset, [
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
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public static function estateMatrix(): array
    {
        $columns = [
            'website' => 'Website',
            'gbp' => 'GBP',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta',
            'ga4' => 'GA4',
            'gsc' => 'GSC',
        ];

        $byType = collect(DemoCatalog::assets())->keyBy('type');

        $cells = [];
        foreach ($columns as $type => $label) {
            $asset = $byType->get($type);
            if ($asset === null) {
                $cells[$type] = ['state' => 'not_configured', 'label' => 'Not configured'];

                continue;
            }
            $enriched = self::enrichAsset($asset);
            if (($enriched['data_state'] ?? '') === 'stale' || ($enriched['data_state'] ?? '') === 'unavailable') {
                $cells[$type] = ['state' => 'data_issue', 'label' => 'Data issue', 'asset_id' => $asset['id'], 'route' => $asset['route']];
            } elseif (($asset['health'] ?? '') === 'needs_attention' || (int) ($asset['open_findings'] ?? 0) >= 2) {
                $cells[$type] = ['state' => 'attention', 'label' => 'Attention', 'asset_id' => $asset['id'], 'route' => $asset['route']];
            } else {
                $cells[$type] = ['state' => 'present', 'label' => 'Present', 'asset_id' => $asset['id'], 'route' => $asset['route']];
            }
        }

        return [
            'columns' => $columns,
            'rows' => [
                [
                    'brand_id' => DemoCatalog::BRAND_ID,
                    'brand' => DemoCatalog::brand()['name'],
                    'customer' => DemoCatalog::customer()['name'],
                    'cells' => $cells,
                ],
            ],
        ];
    }

    /**
     * @return array{managed: int, needs_attention: int, data_issues: int, active_work: int}
     */
    public static function assetsGlance(array $assets): array
    {
        $enriched = array_map([self::class, 'enrichAsset'], $assets);

        return [
            'managed' => count($enriched),
            'needs_attention' => collect($enriched)->whereIn('health', ['needs_attention', 'warning'])->count(),
            'data_issues' => collect($enriched)->whereIn('data_state', ['stale', 'unavailable'])->count(),
            'active_work' => collect($enriched)->filter(static fn (array $a): bool => ((int) ($a['open_tasks'] ?? 0)) > 0)->count(),
        ];
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
                        'route' => 'demo.integrations.google',
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
                        'route' => 'demo.integrations.meta',
                        'manage_label' => 'Manage',
                    ],
                    [
                        'id' => 'dataforseo',
                        'name' => 'DataForSEO',
                        'logo_type' => 'gsc',
                        'state' => 'configuration_incomplete',
                        'state_label' => 'Configuration incomplete',
                        'resources_discovered' => 0,
                        'bound' => 0,
                        'available' => 0,
                        'last_check' => 'Yesterday',
                        'dependent_assets' => 1,
                        'route' => 'demo.integrations',
                        'manage_label' => 'Review',
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
                        'state' => 'connected',
                        'state_label' => 'Connected',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => 'Today',
                        'dependent_assets' => 0,
                        'note' => 'Provider availability ≠ AI Recommendations enabled everywhere.',
                        'route' => 'demo.settings',
                        'route_params' => ['section' => 'ai'],
                        'manage_label' => 'AI settings',
                    ],
                    [
                        'id' => 'anthropic',
                        'name' => 'Anthropic',
                        'logo_type' => 'website',
                        'state' => 'not_connected',
                        'state_label' => 'Not connected',
                        'resources_discovered' => null,
                        'bound' => null,
                        'available' => null,
                        'last_check' => '—',
                        'dependent_assets' => 0,
                        'note' => 'Optional intelligence provider.',
                        'route' => 'demo.settings',
                        'route_params' => ['section' => 'ai'],
                        'manage_label' => 'AI settings',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function googleIntegration(): array
    {
        $resourceGroups = [
            [
                'type' => 'google_ads',
                'label' => 'Google Ads',
                'accounts' => 4,
                'bound' => 4,
                'available' => 0,
            ],
            [
                'type' => 'ga4',
                'label' => 'Google Analytics',
                'accounts' => 6,
                'bound' => 4,
                'available' => 2,
            ],
            [
                'type' => 'gsc',
                'label' => 'Search Console',
                'accounts' => 8,
                'bound' => 6,
                'available' => 2,
            ],
            [
                'type' => 'gbp',
                'label' => 'Google Business Profile',
                'accounts' => 5,
                'bound' => 5,
                'available' => 0,
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
                    'route' => 'demo.analytics',
                ],
                [
                    'resource' => 'GSC sc-domain:atlasdental.example',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental — Search Console',
                    'asset_id' => DemoCatalog::GSC_ASSET_ID,
                    'route' => 'demo.search-console',
                ],
                [
                    'resource' => 'Google Ads customer 123-456-7890',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental — Google Ads',
                    'asset_id' => DemoCatalog::GOOGLE_ADS_ASSET_ID,
                    'route' => 'demo.google-ads.overview',
                ],
                [
                    'resource' => 'GBP location Çankaya',
                    'binding' => 'Google binding',
                    'asset' => 'Atlas Dental Ankara',
                    'asset_id' => DemoCatalog::GBP_ASSET_ID,
                    'route' => 'demo.gbp',
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
     * @return list<array<string, mixed>>
     */
    public static function activityTimeline(): array
    {
        return [
            [
                'id' => 'act-ga4-collect',
                'time' => '04:18',
                'when' => 'Today',
                'title' => 'Google Analytics collection completed',
                'scope' => 'Atlas Dental · GA4',
                'detail' => '342 Evidence records',
                'actor' => 'System',
                'actor_kind' => 'system',
                'status' => 'success',
                'asset_type' => 'ga4',
                'route' => 'demo.analytics',
            ],
            [
                'id' => 'act-rec-accept',
                'time' => '03:46',
                'when' => 'Today',
                'title' => 'Recommendation accepted',
                'scope' => 'Atlas Dental · Website',
                'detail' => 'Canonical alignment',
                'actor' => 'Yakup Udül',
                'actor_kind' => 'human',
                'status' => 'success',
                'asset_type' => 'website',
                'route' => 'demo.recommendations',
            ],
            [
                'id' => 'act-finding-ack',
                'time' => '02:10',
                'when' => 'Today',
                'title' => 'Finding acknowledged',
                'scope' => 'Atlas Dental · Google Ads',
                'detail' => 'Lead measurement requires investigation',
                'actor' => 'Ayşe Demir',
                'actor_kind' => 'human',
                'status' => 'success',
                'asset_type' => 'google_ads',
                'route' => 'demo.findings',
            ],
            [
                'id' => 'act-meta-import',
                'time' => '01:02',
                'when' => 'Today',
                'title' => 'Meta data import running',
                'scope' => 'Atlas Dental · Meta Ads',
                'detail' => '11 / 31 accounts ready',
                'actor' => 'System',
                'actor_kind' => 'system',
                'status' => 'running',
                'asset_type' => 'meta_ads',
                'route' => 'demo.integrations.meta',
            ],
            [
                'id' => 'act-task-complete',
                'time' => '18:40',
                'when' => 'Yesterday',
                'title' => 'Task completed',
                'scope' => 'Atlas Dental · GBP',
                'detail' => 'Audit GBP relevance for implant ankara',
                'actor' => 'Selin Kaya',
                'actor_kind' => 'human',
                'status' => 'success',
                'asset_type' => 'gbp',
                'route' => 'demo.tasks',
            ],
            [
                'id' => 'act-bind',
                'time' => '16:12',
                'when' => 'Yesterday',
                'title' => 'Resource bound',
                'scope' => 'Google → Atlas Dental — GA4',
                'detail' => 'Property 445566778',
                'actor' => 'Ayşe Demir',
                'actor_kind' => 'human',
                'status' => 'success',
                'asset_type' => 'ga4',
                'route' => 'demo.integrations.google',
            ],
            [
                'id' => 'act-gsc',
                'time' => '11:05',
                'when' => '2 days ago',
                'title' => 'Search Console analysis completed',
                'scope' => 'Atlas Dental · Search Console',
                'detail' => 'Query + page snapshots refreshed',
                'actor' => 'System',
                'actor_kind' => 'system',
                'status' => 'success',
                'asset_type' => 'gsc',
                'route' => 'demo.search-console',
            ],
            [
                'id' => 'act-host-fail',
                'time' => '09:20',
                'when' => '4 days ago',
                'title' => 'Hosting probe failed',
                'scope' => 'DemoHost · Atlas Dental',
                'detail' => 'Timeout (demo)',
                'actor' => 'System',
                'actor_kind' => 'system',
                'status' => 'failed',
                'asset_type' => 'hosting',
                'route' => 'demo.hosting',
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'general', 'label' => 'General', 'description' => 'Agency identity, locale, timezone, display defaults.'],
            ['id' => 'team', 'label' => 'Team & Access', 'description' => 'Users, roles, Brand and Digital Asset responsibility.'],
            ['id' => 'notifications', 'label' => 'Notifications', 'description' => 'In-app preferences for meaningful operational events.'],
            ['id' => 'operations', 'label' => 'Operations', 'description' => 'Task due defaults, outcome review window, dashboard mode.'],
            ['id' => 'ai', 'label' => 'AI & Intelligence', 'description' => 'Provider availability and guidance preferences — not autonomous actions.'],
            ['id' => 'privacy', 'label' => 'Data & Privacy', 'description' => 'Retention context and export/purge information.'],
            ['id' => 'advanced', 'label' => 'Advanced', 'description' => 'Environment info, diagnostics, Demo Mode controls.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settingsPayload(): array
    {
        return [
            'general' => [
                'agency_name' => 'Moximu',
                'default_locale' => 'tr_TR',
                'default_timezone' => 'Europe/Istanbul',
                'default_display_currency' => 'TRY',
                'currency_note' => 'Display currency does not overwrite provider-native currency (e.g. Google Ads EUR stays EUR).',
                'default_analytical_date_range' => 'last_28',
                'week_starts_on' => 'monday',
            ],
            'team' => DemoCatalog::teamMembers(),
            'notifications' => [
                ['event' => 'Critical Finding', 'channel' => 'In-app', 'enabled' => true],
                ['event' => 'Integration failure', 'channel' => 'In-app', 'enabled' => true],
                ['event' => 'Task assigned', 'channel' => 'In-app', 'enabled' => true],
                ['event' => 'Task overdue', 'channel' => 'In-app', 'enabled' => true],
                ['event' => 'Regression observed', 'channel' => 'In-app', 'enabled' => true],
                ['event' => 'Provider authorization issue', 'channel' => 'In-app', 'enabled' => true],
                ['event' => 'Operation failed', 'channel' => 'In-app', 'enabled' => true],
            ],
            'operations' => [
                'default_task_due' => 'Next business day',
                'outcome_review_window' => '14 days',
                'archive_behavior' => 'Soft archive with restore',
                'default_dashboard_mode' => 'My Work',
            ],
            'ai' => [
                'openai' => 'Connected',
                'anthropic' => 'Not connected',
                'note' => 'Connected AI providers do not auto-accept Recommendations or create Tasks.',
            ],
            'privacy' => [
                'retention' => 'Operational Evidence retained per agency policy (demo placeholder).',
                'export' => 'Operator export tooling is not part of this Demo shell.',
                'purge' => 'Purge requests are handled out-of-band for Demo Mode.',
            ],
            'advanced' => [
                'environment' => config('app.env'),
                'app_name' => config('app.name'),
                'canonical_surface' => '/app',
                'system_panel' => '/system',
            ],
        ];
    }

    private static function greeting(): string
    {
        $hour = (int) now()->timezone(config('app.timezone'))->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
