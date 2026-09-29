<?php

namespace App\Listeners\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Events\Collection\CollectionRunCompleted;
use App\Jobs\Queries\AggregateQuerySourcesJob;
use App\Models\Collection\CollectionDatasetRun;

/**
 * After a Search Console / Google Ads collection (any discovered account, bound or not): the months of the query
 * datasets that were written are re-aggregated into `query_sources`.
 */
final class AggregateQuerySourcesAfterCollection
{
    /** Query datasets feeding the raw query layer. */
    public const array QUERY_DATASETS = ['gsc_query_page_daily', 'google_ads_search_term_daily'];

    public function handle(CollectionRunCompleted $event): void
    {
        $run = $event->collectionRun;
        if (! in_array($run->status, [CollectionRunStatus::Completed, CollectionRunStatus::Partial], true)) {
            return;
        }
        $ranges = [];
        $run->datasetRuns()->with('resourceRun')
            ->whereIn('dataset_contract_id', self::QUERY_DATASETS)
            ->where('status', CollectionRunStatus::Completed->value)
            ->get()
            ->each(function (CollectionDatasetRun $dataset) use (&$ranges): void {
                $resourceId = (int) ($dataset->resourceRun?->external_resource_id ?? 0);
                $start = data_get($dataset->metadata, 'date_range.start');
                $end = data_get($dataset->metadata, 'date_range.end');
                if ($resourceId < 1 || ! is_string($start) || ! is_string($end)) {
                    return;
                }
                $ranges[$resourceId] = [
                    min($ranges[$resourceId][0] ?? $start, $start),
                    max($ranges[$resourceId][1] ?? $end, $end),
                ];
            });
        foreach ($ranges as $resourceId => [$from, $to]) {
            AggregateQuerySourcesJob::dispatch($resourceId, $from, $to);
        }
    }
}
