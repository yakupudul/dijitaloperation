<?php

return [
    'registry_overlay' => [
        'overlay_id' => 'META_ADS_LEGACY_OVERLAP_RETIREMENT_V1',
        'request_families' => [
            [
                'id' => 'RF_META_INSIGHTS_SYNC',
                'provider_or_source' => 'META_ADS',
                'status' => 'DEFERRED',
                'notes' => 'Superseded by META_V2 account/campaign/adset/ad performance families.',
            ],
            [
                'id' => 'RF_META_INSIGHTS_DAILY',
                'provider_or_source' => 'META_ADS',
                'status' => 'DEFERRED',
                'notes' => 'Superseded by META_V2 account/campaign/adset/ad performance families.',
            ],
            [
                'id' => 'RF_META_TYPED_ACTIONS',
                'provider_or_source' => 'META_ADS',
                'status' => 'DEFERRED',
                'notes' => 'Superseded by META_V2_RF_TYPED_ACTIONS.',
            ],
            [
                'id' => 'RF_META_INSIGHTS_BREAKDOWN',
                'provider_or_source' => 'META_ADS',
                'status' => 'DEFERRED',
                'notes' => 'Superseded by META_V2_RF_BREAKDOWNS.',
            ],
        ],
    ],
];
