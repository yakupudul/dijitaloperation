<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\Contracts\RetryPolicy;

final class DefaultRetryPolicy implements RetryPolicy
{
    public function shouldRetry(
        CollectionDatasetRun $datasetRun,
        CollectionErrorCategory $category,
        int $attemptNumber,
    ): bool {
        if ($attemptNumber >= (int) $datasetRun->max_attempts) {
            return false;
        }

        return match ($category) {
            CollectionErrorCategory::Timeout,
            CollectionErrorCategory::Network,
            CollectionErrorCategory::Provider5xx,
            CollectionErrorCategory::RateLimit => true,
            CollectionErrorCategory::Quota,
            CollectionErrorCategory::Authentication,
            CollectionErrorCategory::Authorization,
            CollectionErrorCategory::InvalidRequest,
            CollectionErrorCategory::ContractMismatch,
            CollectionErrorCategory::UnimplementedCapability,
            CollectionErrorCategory::Cancelled,
            CollectionErrorCategory::Normalization,
            CollectionErrorCategory::Persistence,
            CollectionErrorCategory::Unknown => false,
        };
    }

    public function backoffSeconds(CollectionDatasetRun $datasetRun, int $attemptNumber): int
    {
        /** @var list<int> $schedule */
        $schedule = config('moxdop-collection.default_backoff_seconds', [30, 90, 180]);
        $index = max(0, $attemptNumber - 1);

        return $schedule[min($index, count($schedule) - 1)] ?? 30;
    }
}
