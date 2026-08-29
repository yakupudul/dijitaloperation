<?php

/**
 * Website Intelligence V1 runtime amendments.
 *
 * The public crawler is provider-neutral and represents what search engines/users can
 * observe. WordPress is an optional deep capability on the same Website Digital Asset.
 */

$column = static fn (string $name, string $type, bool $nullable = true, string $role = 'dimension', mixed $default = null): array => array_filter([
    'name' => $name,
    'type' => $type,
    'nullable' => $nullable,
    'role' => $role,
    'default' => $default,
], static fn (mixed $value, string $key): bool => $key !== 'default' || $value !== null, ARRAY_FILTER_USE_BOTH);

$provenance = [
    $column('contract_version', 'integer', false, 'provenance'),
    $column('last_collection_run_id', 'bigint', true, 'provenance'),
    $column('last_dataset_run_id', 'bigint', true, 'provenance'),
    $column('first_collected_at', 'timestamptz', false, 'provenance'),
    $column('last_collected_at', 'timestamptz', false, 'provenance'),
    $column('source_timezone', 'text', true, 'provenance'),
    $column('record_fingerprint', 'char(64)', false, 'provenance'),
    $column('metadata', 'json', true, 'extension'),
];

return [
    'registry_overlay' => [
        'overlay_id' => 'WEBSITE_INTELLIGENCE_V1',
        'request_families' => [
            [
                'id' => 'WEB_RF_WP_REST',
                'provider_or_source' => 'WEBSITE_DIRECT',
                'capability_endpoint_resource' => 'WordPress REST public content inventory',
                'requirement_ids' => [
                    'WEB_CONTENT_META',
                    'WEB_CONTENT_TITLE',
                    'WEB_HEALTH_WP_UPDATES',
                    'WEB_INFRA_CMS',
                ],
                'status' => 'COLLECTION_READY',
            ],
        ],
    ],

    // Used by the integrity registry runtime overlay for datasets introduced here.
    'integrity_request_families' => [
        'website_cms_object_snapshot' => ['WEB_RF_WP_REST'],
        'website_link_edge' => ['WEB_RF_PUBLIC_CRAWL', 'WEB_RF_HTTP_HTML_DIAGNOSIS'],
        'website_crawl_issue_snapshot' => ['WEB_RF_PUBLIC_CRAWL', 'WEB_RF_HTTP_HTML_DIAGNOSIS'],
    ],

    'physical_additions' => [
        'website_cms_object_snapshot' => [
            'table' => 'website_cms_object_snapshot',
            'provider_or_source' => 'WORDPRESS_SITE_CONNECTOR',
            'storage_class' => 'NORMALIZED_SNAPSHOT',
            'write_mode' => 'UPSERT_CURRENT_STATE',
            'partition_strategy' => 'NONE',
            'partition_column' => null,
            'grain' => ['digital_asset_id', 'cms', 'object_type', 'object_id'],
            'natural_key' => ['digital_asset_id', 'cms', 'object_type', 'object_id'],
            'columns' => [
                $column('digital_asset_id', 'bigint', false, 'scope'),
                $column('external_resource_id', 'bigint', true, 'scope'),
                $column('cms', 'text', false, 'identity'),
                $column('object_type', 'text', false, 'identity'),
                $column('object_id', 'text', false, 'identity'),
                $column('status', 'text', true),
                $column('slug', 'text', true),
                $column('permalink', 'text', true),
                $column('title', 'text', true),
                $column('published_at', 'timestamptz', true),
                $column('modified_at', 'timestamptz', true),
                $column('parent_id', 'text', true),
                $column('template', 'text', true),
                $column('featured_media_id', 'text', true),
                $column('observed_at', 'timestamptz', false, 'identity'),
                ...$provenance,
            ],
        ],

        'website_link_edge' => [
            'table' => 'website_link_edge',
            'provider_or_source' => 'WEBSITE_DIRECT',
            'storage_class' => 'NORMALIZED_SNAPSHOT',
            'write_mode' => 'UPSERT_CURRENT_STATE',
            'partition_strategy' => 'NONE',
            'partition_column' => null,
            'grain' => ['digital_asset_id', 'edge_key', 'observed_at'],
            'natural_key' => ['digital_asset_id', 'edge_key', 'observed_at'],
            'columns' => [
                $column('digital_asset_id', 'bigint', false, 'scope'),
                $column('external_resource_id', 'bigint', true, 'scope'),
                $column('edge_key', 'char(64)', false, 'identity'),
                $column('source_url', 'text', false, 'identity'),
                $column('target_url', 'text', false),
                $column('normalized_target_url', 'text', false),
                $column('is_internal', 'boolean', false, 'dimension', false),
                $column('anchor_text', 'text', true),
                $column('rel', 'text', true),
                $column('nofollow', 'boolean', false, 'dimension', false),
                $column('observed_at', 'timestamptz', false, 'identity'),
                ...$provenance,
            ],
        ],

        'website_crawl_issue_snapshot' => [
            'table' => 'website_crawl_issue_snapshot',
            'provider_or_source' => 'WEBSITE_DIRECT',
            'storage_class' => 'NORMALIZED_SNAPSHOT',
            'write_mode' => 'UPSERT_CURRENT_STATE',
            'partition_strategy' => 'NONE',
            'partition_column' => null,
            'grain' => ['digital_asset_id', 'url', 'issue_code', 'observed_at'],
            'natural_key' => ['digital_asset_id', 'url', 'issue_code', 'observed_at'],
            'columns' => [
                $column('digital_asset_id', 'bigint', false, 'scope'),
                $column('external_resource_id', 'bigint', true, 'scope'),
                $column('url', 'text', false, 'identity'),
                $column('issue_code', 'text', false, 'identity'),
                $column('severity', 'text', false),
                $column('message', 'text', false),
                $column('observed_at', 'timestamptz', false, 'identity'),
                ...$provenance,
            ],
        ],
    ],
];
