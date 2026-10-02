<?php

namespace App\Listeners;

use App\Events\Collection\CollectionRunCompleted;
use App\Jobs\Async\PublicDiscoveryJob;
use App\Models\Run;
use App\Support\Async\AsyncOperationTypes;

final class ResumePublicDiscoveryAfterCollection
{
    public function handle(CollectionRunCompleted $event): void
    {
        $collection = $event->collectionRun;
        $operationId = data_get($collection->request_context, 'context.public_discovery_operation_id');
        if (! $collection->status->isTerminal() || ! is_numeric($operationId)
            || $collection->idempotency_key !== 'public-discovery:'.$operationId) {
            return;
        }
        $run = Run::query()->where('digital_asset_id', $collection->digital_asset_id)
            ->where('metadata->operation_type', AsyncOperationTypes::PUBLIC_DISCOVERY)
            ->whereIn('status', ['queued', 'running'])->find((int) $operationId);
        if ($run !== null) {
            PublicDiscoveryJob::dispatch($run->id)->afterCommit();
        }
    }
}
