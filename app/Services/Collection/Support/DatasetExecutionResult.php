<?php

namespace App\Services\Collection\Support;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;

final class DatasetExecutionResult
{
    /**
     * @param  array<string, mixed>|null  $checkpoint
     */
    public function __construct(
        public readonly DatasetExecutionOutcome $outcome,
        public readonly ?ProgressMode $progressMode = null,
        public readonly ?int $progressCurrent = null,
        public readonly ?int $progressTotal = null,
        public readonly int $rowsReceived = 0,
        public readonly int $rowsWritten = 0,
        public readonly int $chunksCompleted = 0,
        public readonly int $pagesCompleted = 0,
        public readonly ?string $stage = null,
        public readonly ?array $checkpoint = null,
        public readonly ?CollectionErrorCategory $errorCategory = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly int $backoffSeconds = 0,
    ) {}

    public static function completed(int $rowsWritten = 0): self
    {
        return new self(
            outcome: DatasetExecutionOutcome::Completed,
            rowsReceived: $rowsWritten,
            rowsWritten: $rowsWritten,
        );
    }

    public static function failed(
        CollectionErrorCategory $category,
        string $message,
        ?string $code = null,
    ): self {
        return new self(
            outcome: DatasetExecutionOutcome::Failed,
            errorCategory: $category,
            errorCode: $code,
            errorMessage: $message,
        );
    }

    public static function retry(
        CollectionErrorCategory $category,
        string $message,
        int $backoffSeconds,
        ?string $code = null,
    ): self {
        return new self(
            outcome: DatasetExecutionOutcome::Retry,
            errorCategory: $category,
            errorCode: $code,
            errorMessage: $message,
            backoffSeconds: $backoffSeconds,
        );
    }

    public static function continueWithCheckpoint(array $checkpoint, int $rowsWritten = 0, int $pages = 0): self
    {
        return new self(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            rowsReceived: $rowsWritten,
            rowsWritten: $rowsWritten,
            pagesCompleted: $pages,
            checkpoint: $checkpoint,
        );
    }
}
