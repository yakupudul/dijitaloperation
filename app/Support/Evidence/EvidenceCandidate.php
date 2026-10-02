<?php

namespace App\Support\Evidence;

use App\Models\DigitalAsset;

/**
 * In-memory eligible Evidence candidate. Not persisted until fingerprint upsert.
 */
final class EvidenceCandidate
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fingerprintInputs
     */
    public function __construct(
        public readonly EvidenceDefinition $definition,
        public readonly DigitalAsset $asset,
        public readonly EvidencePeriod $period,
        public readonly string $title,
        public readonly array $payload,
        public readonly array $fingerprintInputs,
        public readonly int $externalResourceId,
        public readonly ?int $collectionRunId,
        public readonly ?int $brandGoalId,
        public readonly ?int $brandOfferingId,
        public readonly \DateTimeInterface $observedAt,
    ) {}
}
