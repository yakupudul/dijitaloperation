<?php

namespace App\Services\Evidence;

/**
 * Canonical Evidence identity fingerprint.
 *
 * Distinct from PaidRequestFingerprint (cost cache) and RecordFingerprint (pool facts).
 * Metric values are excluded so identity is stable across refreshes of the same statement.
 */
final class EvidenceIdentityFingerprint
{
    public const string VERSION = 'v1';

    /**
     * @param  array<string, scalar|null>  $inputs
     */
    public function make(array $inputs): string
    {
        ksort($inputs);
        $normalized = [];
        foreach ($inputs as $key => $value) {
            $normalized[$key] = $value === null ? '' : (string) $value;
        }

        $payload = [
            'version' => self::VERSION,
            'inputs' => $normalized,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
