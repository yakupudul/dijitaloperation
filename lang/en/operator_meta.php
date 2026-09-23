<?php

return [
    'identity' => [
        'status' => [
            'connected' => 'Connected',
            'action_required' => 'Action required',
            'not_connected' => 'Not connected',
            'error' => 'Error',
        ],
        'title_not_connected' => ':name — not connected',
        'title_read_error' => ':name — read error',
    ],

    'boundary' => [
        'real' => 'Meta Ads workspace uses collected data. Unbacked cards stay empty. Live API calls are not made on page render.',
        'unbound' => 'Meta Ads workspace has no usable Ad Account binding. Live API calls are not made on page render.',
    ],

    'integration' => [
        'binding' => 'Meta Ads connection',
        'data_label' => 'Not collected yet — see data status',
    ],

    'measurement' => [
        'lead_hint' => 'Meta’s standard lead result',
        'whatsapp_rule' => 'WhatsApp cost is only calculated when the ad set destination is WhatsApp and Meta reports conversations started. Other messaging results are not counted as WhatsApp results.',
        'raw_action' => 'Show Meta’s technical name',
        'actions_intro' => 'Different results are never added together, and results with similar technical names are not treated as the same outcome.',
        'actions_empty' => 'No measured results in the selected period.',
        'footer' => 'Headline results also appear in context on Overview, Performance, Campaigns and, where they can be linked, Creatives. This detailed table keeps every result Meta reports separate.',
    ],

    'health' => [
        'datasets' => [
            'meta_account_daily' => 'Account daily performance',
            'meta_campaign_daily' => 'Campaign daily performance',
            'meta_adset_daily' => 'Ad set daily performance',
            'meta_ad_daily' => 'Ad daily performance',
            'meta_typed_action_daily' => 'Result (action) records',
            'meta_video_engagement_daily' => 'Video engagement',
            'meta_analysis_breakdown_daily' => 'Audience and placement breakdowns',
            'meta_hourly_daily' => 'Hourly performance',
            'meta_ad_snapshot' => 'Ad inventory',
            'meta_adset_targeting_snapshot' => 'Targeting settings',
            'meta_conversion_source_snapshot' => 'Pixels and custom conversions',
            'meta_change_event' => 'Account change history',
            'meta_creative_snapshot' => 'Creative inventory',
        ],
        'freshness' => [
            'FRESH' => 'Current',
            'FRESH_WITH_LIMITATION' => 'Current (limited)',
            'DUE' => 'Refresh due',
            'STALE' => 'Stale',
            'PARTIAL' => 'Partial',
            'ACTION_REQUIRED' => 'Action required',
            'PROVIDER_LIMITED' => 'Limited by Meta',
            'INTEGRITY_BLOCKED' => 'Awaiting verification',
            'UNKNOWN' => 'Unknown',
        ],
        'coverage' => [
            'FULLY_COVERED' => 'Whole period available',
            'PARTIALLY_COVERED' => 'Part of the period available',
            'NOT_COVERED' => 'No data for this period',
        ],
        'integrity' => [
            'READY_FOR_REAL_UI' => 'Verified',
            'READY_WITH_PROVIDER_LIMITATION' => 'Verified (Meta limitation)',
            'BLOCKED_PARTIAL' => 'Held back: incomplete data',
            'BLOCKED_INTEGRITY' => 'Failed consistency checks',
            'BLOCKED_STALE' => 'Held back: data is stale',
            'BLOCKED_CONTRACT' => 'Data contract missing',
            'UNVERIFIED' => 'Not verified yet',
            'UNAVAILABLE' => 'Unavailable',
        ],
        'freshness_label' => 'Freshness',
        'coverage_label' => 'Coverage',
        'integrity_label' => 'Verification',
    ],

    'instagram' => [
        'not_connected_title' => 'Instagram analytics are not connected yet',
        'not_connected_body' => 'This system does not collect Instagram posts, followers or engagement. No Instagram performance numbers are shown here, and missing data is never presented as zero.',
        'profile_title' => 'Last collected profile details',
        'profile_observed' => 'Collected: :time',
        'profile_missing' => 'No profile details have been collected for this account yet.',
        'username' => 'Username',
        'name' => 'Display name',
        'account_type' => 'Account type',
        'biography' => 'Bio',
        'website' => 'Website',
        'brand' => 'Brand',
        'open_brand' => 'Open brand',
        'all_assets' => 'All digital assets',
        'view_activity' => 'View activity',
    ],
];
