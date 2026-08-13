<?php

namespace App\Support\Demo;

/**
 * Deterministic Instagram Digital Asset workspace fixtures (Demo Mode).
 * No live Meta / Instagram Graph API calls.
 */
final class InstagramWorkspaceFixtures
{
    public const string ASSET_ID = 'ig-atlas';

    /**
     * @return array<string, mixed>
     */
    public static function workspace(?string $assetId = null): array
    {
        $assetId ??= self::ASSET_ID;

        return [
            'demo_boundary' => 'Demo Mode · Instagram workspace fixtures — no live Graph API',
            'identity' => self::identity($assetId),
            'overview' => self::overview(),
            'profile' => self::profile(),
            'relationships' => self::relationships(),
            'findings' => self::findings(),
            'activity' => self::activity(),
            'settings' => self::settings(),
            'tabs' => [
                'overview' => 'Overview',
                'profile' => 'Profile',
                'relationships' => 'Relationships',
                'findings' => 'Findings',
                'activity' => 'Activity',
                'settings' => 'Settings',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function identity(string $assetId): array
    {
        return [
            'asset_id' => $assetId,
            'eyebrow' => 'Instagram',
            'title' => 'Atlas Dental Ankara',
            'handle' => '@atlasdentalankara',
            'brand_id' => DemoCatalog::BRAND_ID,
            'brand_name' => 'Atlas Dental Ankara',
            'connection' => 'Public discovery + demo binding',
            'freshness' => 'Profile snapshot · 6h ago',
            'status' => 'Observed',
            'status_note' => 'Read-only observation — no publish or DM actions.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function overview(): array
    {
        return [
            'glance' => [
                ['label' => 'Followers', 'value' => '12.4K', 'hint' => '+2.1% · 28d'],
                ['label' => 'Posts (28d)', 'value' => '18', 'hint' => 'Reels 11 · Feed 7'],
                ['label' => 'Reach', 'value' => '86.2K', 'hint' => 'Accounts reached'],
                ['label' => 'Profile visits', 'value' => '3.1K', 'hint' => 'From ads + organic'],
            ],
            'needs_attention' => [
                [
                    'title' => 'Website URL mismatch vs Website asset',
                    'severity' => 'warning',
                    'summary' => 'Bio link points to /kampanya while Website primary URL is the homepage.',
                ],
                [
                    'title' => 'Meta Ads destination ≠ Instagram bio',
                    'severity' => 'info',
                    'summary' => 'Awareness campaigns drive profile visits; lead ads use a different landing path.',
                ],
            ],
            'content_mix' => [
                ['label' => 'Reels', 'share' => 61],
                ['label' => 'Feed', 'share' => 28],
                ['label' => 'Stories (avg/day)', 'share' => 11],
            ],
            'recent_posts' => [
                ['title' => 'Implant consult Reel', 'type' => 'Reel', 'published' => '2d ago', 'reach' => '14.2K', 'engagement' => '6.8%'],
                ['title' => 'Clinic tour carousel', 'type' => 'Feed', 'published' => '5d ago', 'reach' => '6.1K', 'engagement' => '4.2%'],
                ['title' => 'Patient FAQ Reel', 'type' => 'Reel', 'published' => '1w ago', 'reach' => '22.0K', 'engagement' => '8.1%'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(): array
    {
        return [
            'display_name' => 'Atlas Dental Ankara',
            'username' => 'atlasdentalankara',
            'category' => 'Dentist',
            'bio' => 'Çankaya · Implant & aesthetic dentistry\nBook consult → atlasdental.example/kampanya',
            'website' => 'https://atlasdental.example/kampanya',
            'contact' => [
                'email' => 'ops@atlashealth.example',
                'phone' => '+90 312 000 00 00',
            ],
            'coverage' => [
                ['field' => 'Profile photo', 'state' => 'complete'],
                ['field' => 'Category', 'state' => 'complete'],
                ['field' => 'Website', 'state' => 'needs_review'],
                ['field' => 'Action button', 'state' => 'complete'],
                ['field' => 'Location label', 'state' => 'complete'],
            ],
            'consistency' => [
                'brand_name_match' => true,
                'website_match' => false,
                'website_note' => 'Differs from Website Digital Asset primary URL (homepage).',
                'phone_match' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function relationships(): array
    {
        return [
            'brand' => [
                'id' => DemoCatalog::BRAND_ID,
                'name' => 'Atlas Dental Ankara',
                'route' => 'demo.brand',
            ],
            'linked_assets' => [
                [
                    'type' => 'website',
                    'label' => 'Website',
                    'name' => 'atlasdental.example',
                    'route' => 'demo.website',
                    'note' => 'Bio URL should reconcile with primary URL',
                ],
                [
                    'type' => 'meta_ads',
                    'label' => 'Meta Ads',
                    'name' => 'Atlas Dental — Meta',
                    'route' => 'demo.meta.overview',
                    'note' => 'Awareness → Instagram profile destination observed',
                ],
                [
                    'type' => 'gbp',
                    'label' => 'GBP',
                    'name' => 'Atlas Dental Ankara',
                    'route' => 'demo.gbp',
                    'note' => 'Local identity alignment (name / city)',
                ],
            ],
            'cross_checks' => [
                [
                    'check' => 'Website ↔ Instagram website URL',
                    'state' => 'needs_attention',
                    'summary' => 'Bio uses /kampanya; Website primary is homepage.',
                ],
                [
                    'check' => 'Instagram ↔ Meta Ads destination',
                    'state' => 'observed',
                    'summary' => 'Profile visit campaigns align; lead forms use alternate path.',
                ],
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
                'id' => 'ig-f-1',
                'severity' => 'warning',
                'title' => 'Bio website path diverges from Website asset',
                'summary' => 'Operators should confirm whether /kampanya is intentional seasonal routing.',
                'status' => 'open',
            ],
            [
                'id' => 'ig-f-2',
                'severity' => 'info',
                'title' => 'Reels concentration high (61%)',
                'summary' => 'Informational — creative mix skews Reels; not an auto-action.',
                'status' => 'open',
            ],
            [
                'id' => 'ig-f-3',
                'severity' => 'success',
                'title' => 'Category and contact fields complete',
                'summary' => 'Dentist category + phone match Brand contact defaults.',
                'status' => 'resolved',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activity(): array
    {
        return [
            ['at' => '6h ago', 'event' => 'Profile snapshot refreshed', 'detail' => 'Public discovery fixtures'],
            ['at' => 'Yesterday', 'event' => 'Cross-asset check reviewed', 'detail' => 'Website ↔ Instagram URL'],
            ['at' => '3d ago', 'event' => 'Finding opened', 'detail' => 'Bio website path divergence'],
            ['at' => '1w ago', 'event' => 'Asset registered', 'detail' => 'Demo Instagram binding'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'connection_mode' => 'Demo / public discovery',
            'write_actions' => 'Disabled (product boundary)',
            'sync_cadence' => 'Manual refresh in Demo Mode',
            'responsible' => 'Ayşe Yılmaz',
            'notes' => [
                'Instagram remains a specialist Digital Asset workspace under /app.',
                'No publish, comment, or DM write actions in MoxDOP.',
                'Provider credentials are not required for Demo fixtures.',
            ],
        ];
    }
}
