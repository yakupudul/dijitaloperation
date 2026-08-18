<?php

namespace App\Support\ReportSnapshots;

/**
 * Deterministic content checksum (SHA-256). Not a digital signature.
 * Excludes snapshot row metadata (id, generated_at, created_at, idempotency).
 */
final class ReportSnapshotChecksum
{
    /**
     * @param  array<string, mixed>  $contentPayload
     */
    public static function hash(array $contentPayload): string
    {
        return hash('sha256', CanonicalJson::encode($contentPayload));
    }

    /**
     * @param  array<string, mixed>  $contentPayload
     */
    public static function verify(array $contentPayload, string $expected): bool
    {
        return hash_equals($expected, self::hash($contentPayload));
    }
}
