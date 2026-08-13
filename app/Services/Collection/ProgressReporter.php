<?php

namespace App\Services\Collection;

use App\Enums\Collection\ProgressMode;
use App\Events\Collection\DatasetRunProgressed;
use App\Models\Collection\CollectionDatasetRun;
use InvalidArgumentException;

final class ProgressReporter
{
    public function report(
        CollectionDatasetRun $datasetRun,
        ProgressMode $mode,
        ?int $current = null,
        ?int $total = null,
        ?string $stage = null,
        int $rowsReceivedDelta = 0,
        int $rowsWrittenDelta = 0,
        int $chunksDelta = 0,
        int $pagesDelta = 0,
    ): void {
        if ($mode === ProgressMode::Counted) {
            if ($total === null || $total <= 0) {
                throw new InvalidArgumentException('Counted progress requires a known positive total.');
            }
            if ($current !== null && $current > $total) {
                $current = $total;
            }
        }

        if ($mode !== ProgressMode::Counted) {
            // Never fabricate a percentage for unknown totals.
            $total = null;
        }

        $datasetRun->forceFill([
            'progress_mode' => $mode,
            'progress_current' => $current,
            'progress_total' => $total,
            'stage' => $stage,
            'rows_received' => (int) $datasetRun->rows_received + $rowsReceivedDelta,
            'rows_written' => (int) $datasetRun->rows_written + $rowsWrittenDelta,
            'chunks_completed' => (int) $datasetRun->chunks_completed + $chunksDelta,
            'pages_completed' => (int) $datasetRun->pages_completed + $pagesDelta,
            'last_activity_at' => now(),
        ])->save();

        DatasetRunProgressed::dispatch($datasetRun->fresh() ?? $datasetRun);
    }
}
