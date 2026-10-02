<?php

namespace App\Listeners\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Events\Collection\CollectionRunCompleted;
use App\Jobs\Async\EvaluateFindingsForAssetJob;
use App\Models\DigitalAsset;

/**
 * Collection persists source facts; analysis starts only after the Website run is terminal.
 */
final class QueueWebsiteAnalysisAfterCollection
{
    public function handle(CollectionRunCompleted $event): void
    {
        $run = $event->collectionRun;
        if (! in_array($run->status, [CollectionRunStatus::Completed, CollectionRunStatus::Partial], true)
            || $run->digital_asset_id === null) {
            return;
        }

        $isWebsite = DigitalAsset::query()
            ->whereKey($run->digital_asset_id)
            ->where('type', 'website')
            ->exists();
        if (! $isWebsite) {
            return;
        }

        EvaluateFindingsForAssetJob::dispatch((int) $run->digital_asset_id);
    }
}
