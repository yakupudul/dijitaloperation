<?php

namespace App\Services\DataPool\Support;

/**
 * Canonical normalized batch — collectors must not include physical table names.
 *
 * @phpstan-type Record array<string, mixed>
 */
final class NormalizedDatasetBatch
{
    /**
     * @param  list<Record>  $records
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly int $datasetRunId,
        public readonly int $contractVersion,
        public readonly string $batchKey,
        public readonly array $records,
        public readonly ?int $digitalAssetId = null,
        public readonly ?int $externalResourceId = null,
        public readonly ?int $collectionRunId = null,
        public readonly ?int $resourceRunId = null,
        public readonly ?string $providerOrSource = null,
        public readonly ?RawPayloadReference $rawPayloadReference = null,
        public readonly ?\DateTimeInterface $providerDataTimestamp = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function resolvedIdempotencyKey(): string
    {
        return $this->idempotencyKey ?? hash('sha256', $this->datasetRunId.'|'.$this->batchKey);
    }
}
