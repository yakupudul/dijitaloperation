<?php

namespace App\Services\DataPool\Support;

/**
 * Provider-neutral raw payload envelope. Must not contain secrets.
 */
final class RawPayloadEnvelope
{
    /**
     * @param  array<string, mixed>  $providerSafeMetadata
     */
    public function __construct(
        public readonly string $providerOrSource,
        public readonly ?int $collectionRunId,
        public readonly ?int $resourceRunId,
        public readonly ?int $datasetRunId,
        public readonly string $logicalDatasetId,
        public readonly ?string $requestFamilyId,
        public readonly string $batchKey,
        public readonly string $contentType,
        public readonly string $payload,
        public readonly ?string $providerRequestFingerprint = null,
        public readonly ?int $recordCount = null,
        public readonly array $providerSafeMetadata = [],
        public readonly ?\DateTimeInterface $capturedAt = null,
        public readonly ?string $encoding = null,
        public readonly bool $alreadyCompressed = false,
        public readonly ?string $retentionClass = null,
    ) {}
}
