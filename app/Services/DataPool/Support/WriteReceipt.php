<?php

namespace App\Services\DataPool\Support;

final class WriteReceipt
{
    public function __construct(
        public readonly int $writeBatchId,
        public readonly string $status,
        public readonly int $rowsReceived,
        public readonly int $rowsInserted,
        public readonly int $rowsUpdated,
        public readonly ?int $rowsUnchanged,
        public readonly bool $checkpointSafe,
        public readonly ?int $rawIngestionObjectId = null,
        public readonly ?\DateTimeInterface $committedAt = null,
        public readonly bool $reusedExisting = false,
        public readonly ?string $errorSummary = null,
    ) {}

    public function isCommitted(): bool
    {
        return $this->status === 'committed' && $this->checkpointSafe;
    }
}
