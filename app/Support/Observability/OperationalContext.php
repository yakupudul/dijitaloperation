<?php

namespace App\Support\Observability;

/**
 * Structured operational context — diagnostic metadata, not business truth.
 * Never carries credentials, tokens, Authorization, raw payloads, or free-text PII.
 *
 * @phpstan-type Context array{
 *     correlation_id?: string|null,
 *     run_id?: int|string|null,
 *     occurrence_id?: int|string|null,
 *     job_id?: string|null,
 *     customer_id?: int|null,
 *     brand_id?: int|null,
 *     digital_asset_id?: int|null,
 *     integration_id?: int|null,
 *     provider?: string|null,
 *     resource_id?: int|null,
 *     dataset_key?: string|null,
 *     queue?: string|null,
 *     worker?: string|null,
 *     operation?: string|null,
 *     status?: string|null,
 *     error_code?: string|null,
 *     duration_ms?: int|null,
 *     attempt?: int|null
 * }
 */
final class OperationalContext
{
    /**
     * @param  Context  $fields
     * @return array<string, mixed>
     */
    public static function make(array $fields): array
    {
        $allowed = [
            'correlation_id', 'run_id', 'occurrence_id', 'job_id',
            'customer_id', 'brand_id', 'digital_asset_id', 'integration_id',
            'provider', 'resource_id', 'dataset_key', 'queue', 'worker',
            'operation', 'status', 'error_code', 'duration_ms', 'attempt',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null && $fields[$key] !== '') {
                $out[$key] = $fields[$key];
            }
        }

        return $out;
    }
}
