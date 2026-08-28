<?php

/**
 * Website Intelligence V1 runtime amendments.
 *
 * WordPress is an optional Website capability, never a separate Digital Asset.
 * The collection planner schedules the public REST probe on the Website asset
 * capability resource; normalized CMS rows retain WORDPRESS_SITE_CONNECTOR
 * provenance in their metadata / write envelope.
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
                // Transport is the Website asset capability. The executor writes
                // source provenance as WORDPRESS_SITE_CONNECTOR.
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
    ],
];
