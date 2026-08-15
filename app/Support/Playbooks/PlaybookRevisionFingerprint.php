<?php

namespace App\Support\Playbooks;

/**
 * Deterministic content fingerprint for revision idempotency (not cross-Playbook dedup).
 */
final class PlaybookRevisionFingerprint
{
    /**
     * @param  array{
     *     title: string,
     *     summary?: string|null,
     *     knowledge?: array<string, mixed>|null,
     *     cadence?: string|null,
     *     service_applicability_mode: string,
     *     asset_applicability_mode: string,
     *     execution_scope_mode: string,
     *     service_definition_ids?: list<int>,
     *     asset_types?: list<string>,
     *     execution_scopes?: list<string>,
     *     instructions?: list<array{title?: string|null, body: string}>,
     *     references?: list<array{kind: string, label: string, url?: string|null, route_name?: string|null, description?: string|null}>,
     * }  $payload
     */
    public static function for(array $payload): string
    {
        $normalized = [
            'title' => trim((string) $payload['title']),
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'knowledge' => $payload['knowledge'] ?? null,
            'cadence' => $payload['cadence'] ?? null,
            'service_applicability_mode' => $payload['service_applicability_mode'],
            'asset_applicability_mode' => $payload['asset_applicability_mode'],
            'execution_scope_mode' => $payload['execution_scope_mode'],
            'service_definition_ids' => array_values(array_map('intval', $payload['service_definition_ids'] ?? [])),
            'asset_types' => array_values($payload['asset_types'] ?? []),
            'execution_scopes' => array_values($payload['execution_scopes'] ?? []),
            'instructions' => array_values(array_map(static function (array $row): array {
                return [
                    'title' => isset($row['title']) ? trim((string) $row['title']) : null,
                    'body' => trim((string) $row['body']),
                ];
            }, $payload['instructions'] ?? [])),
            'references' => array_values(array_map(static function (array $row): array {
                return [
                    'kind' => $row['kind'],
                    'label' => trim((string) $row['label']),
                    'url' => $row['url'] ?? null,
                    'route_name' => $row['route_name'] ?? null,
                    'description' => isset($row['description']) ? trim((string) $row['description']) : null,
                ];
            }, $payload['references'] ?? [])),
        ];

        sort($normalized['service_definition_ids']);
        sort($normalized['asset_types']);
        sort($normalized['execution_scopes']);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
