<?php

namespace App\Listeners\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Events\Collection\CollectionRunCompleted;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;

final class QueueWebsiteProjectionAfterCollection
{
    public function handle(CollectionRunCompleted $event): void
    {
        $run = $event->collectionRun;
        if (! in_array($run->status, [CollectionRunStatus::Completed, CollectionRunStatus::Partial], true)) {
            return;
        }

        $datasetIds = $run->datasetRuns()->pluck('dataset_contract_id');
        $isProjectionSource = $datasetIds->contains(static fn (mixed $datasetId): bool =>
            str_starts_with((string) $datasetId, 'website_')
            || str_starts_with((string) $datasetId, 'gsc_')
            || str_starts_with((string) $datasetId, 'ga4_')
        );
        if (! $isProjectionSource) {
            return;
        }

        $assetIds = collect();
        if ($run->digital_asset_id !== null) {
            $isWebsite = DigitalAsset::query()
                ->whereKey($run->digital_asset_id)
                ->where('type', 'website')
                ->exists();
            if ($isWebsite) {
                $assetIds->push((int) $run->digital_asset_id);
            }
        }

        $resourceIds = $run->resourceRuns()
            ->whereNotNull('external_resource_id')
            ->pluck('external_resource_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($resourceIds !== []) {
            $boundWebsiteIds = CoreAssetBinding::query()
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->whereIn('external_resource_id', $resourceIds)
                ->whereHas('digitalAsset', static fn ($query) => $query->where('type', 'website'))
                ->pluck('digital_asset_id');
            $assetIds = $assetIds->merge($boundWebsiteIds);
        }

        foreach ($assetIds->map(static fn (mixed $id): int => (int) $id)->unique()->values() as $assetId) {
            RebuildWebsiteProjectionJob::dispatch(
                websiteAssetId: $assetId,
                trigger: 'collection_completed',
                triggerCollectionRunId: (int) $run->getKey(),
            );
        }
    }
}
