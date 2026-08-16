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
            'health' => ['findings' => [], 'groups' => [], 'note' => 'No Website health observations collected yet.'],
            'visibility' => [],
            'content_workspace' => ['directory' => []],
            'performance_workspace' => [],
            'connections' => [],
            'activity' => [],
            'settings' => [],
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

        return [
            'migration_mode' => 'unavailable',
            'demo_boundary' => 'Production GBP specialist analytics are not fully productionized for this asset — no Demo fixtures, fake ranks, or sample reviews are shown.',
            'identity' => [
                'eyebrow' => 'Google Business Profile',
                'title' => ($asset?->name ?? 'GBP').' — not collected',
                'brand' => $asset?->brand?->name,
                'brand_id' => $asset?->brand_id ?? 0,
                'brand_name' => $asset?->brand?->name ?? '—',
                'status' => 'Unavailable',
            ],
            'glance' => [],
            'needs_attention' => [],
            'opportunities' => [],
            'profile' => [],
            'visibility' => ['grid' => [], 'note' => 'Local visibility grid is unavailable — no fabricated rankings.'],
            'performance' => [
                'queries' => ['rows' => []],
                'discovery' => ['series_search' => ['labels' => [], 'values' => []], 'series_maps' => ['labels' => [], 'values' => []]],
                'actions' => ['series' => ['labels' => [], 'values' => []]],
            ],
            'reviews' => ['inbox' => []],
            'competitors' => [],
            'operations' => ['findings' => [], 'recommendations' => [], 'tasks' => [], 'outcomes' => []],
            'health' => ['findings' => []],
            'activity' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function instagram(string $assetId): array
    {
        $asset = self::asset($assetId);

        return [
            'migration_mode' => 'unavailable',
            'demo_boundary' => 'Instagram analytics provider integration is unavailable — asset record/setup may exist, but no simulated analytics are shown.',
            'identity' => [
                'eyebrow' => 'Instagram',
                'title' => ($asset?->name ?? 'Instagram').' — analytics unavailable',
                'brand' => $asset?->brand?->name,
                'brand_id' => $asset?->brand_id ?? 0,
                'brand_name' => $asset?->brand?->name ?? '—',
                'status' => 'Unavailable',
            ],
            'profile' => [],
            'glance' => [],
            'needs_attention' => [],
            'operations' => [],
            'setup' => [
                'note' => 'Connect and bind an Instagram resource when provider analytics support is available. No sample metrics are shown.',
            ],
        ];
    }

    private static function asset(string $assetId): ?DigitalAsset
    {
        if (! ctype_digit($assetId)) {
            return null;
        }

        return DigitalAsset::query()->with('brand')->find((int) $assetId);
    }
}
