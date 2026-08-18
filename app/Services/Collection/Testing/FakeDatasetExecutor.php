<?php

namespace App\Services\Collection\Testing;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;

/**
 * Test double — not a production provider collector.
 */
final class FakeDatasetExecutor implements DatasetExecutor
{
    /**
     * @param  list<string>  $families
     * @param  array<string, DatasetExecutionResult>|null  $resultsByFamily
     */
    public function __construct(
        private readonly array $families,
        private readonly ?DatasetExecutionResult $defaultResult = null,
        private readonly ?array $resultsByFamily = null,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return $this->families;
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        if ($this->resultsByFamily !== null) {
            return $this->resultsByFamily[$context->datasetRun->request_family_id]
                ?? $this->defaultResult
                ?? DatasetExecutionResult::completed(1);
        }

        return $this->defaultResult ?? DatasetExecutionResult::completed(1);
    }

    public static function succeed(string ...$families): self
    {
        return new self(array_values($families), DatasetExecutionResult::completed(3));
    }

    public static function fail(CollectionErrorCategory $category, string $message, string ...$families): self
    {
        return new self(array_values($families), DatasetExecutionResult::failed($category, $message));
    }

    /**
     * @param  array<string, DatasetExecutionResult>  $byFamily
     * @param  list<string>  $families
     */
    public static function map(array $byFamily): self
    {
        return new self(array_keys($byFamily), null, $byFamily);
    }

    public static function countedProgress(string $family, int $current, int $total): self
    {
        return new self([$family], new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: $total,
            rowsWritten: $current,
        ));
    }
}
