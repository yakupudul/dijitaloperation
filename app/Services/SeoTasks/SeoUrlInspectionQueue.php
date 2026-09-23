<?php

namespace App\Services\SeoTasks;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use Throwable;

/**
 * Points Search Console URL inspection at the pages that matter (service pages, homepage, pages
 * with search traffic) instead of leaving it unused. At most one run per site per ISO week, within
 * the collector's per-run quota. Read-only towards Google: inspection only reads index status.
 */
final class SeoUrlInspectionQueue
{
    public function __construct(private readonly StartCollectionService $starter) {}

    /**
     * @param  list<string>  $targets  absolute URLs, most important first
     * @return array{status: string, targets: int, run_id?: int, message?: string}
     */
    public function queue(DigitalAsset $site, array $targets, ?User $actor = null): array
    {
        $targets = array_values(array_unique(array_filter($targets, static fn ($url): bool => is_string($url) && str_starts_with($url, 'http'))));
        $max = min(SeoTaskConfig::int('indexing.inspection_max_targets', 20), (int) config('moxdop-gsc-collector.url_inspection_max_targets_per_run', 25));
        $targets = array_slice($targets, 0, max(0, $max));
        if ($targets === []) {
            return ['status' => 'nothing_to_inspect', 'targets' => 0];
        }
        $binding = CoreAssetBinding::query()
            ->where('digital_asset_id', $site->id)
            ->where('capability', 'search_console')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->first();
        if ($binding === null) {
            return ['status' => 'not_bound', 'targets' => 0];
        }

        try {
            $run = $this->starter->start(new StartCollectionRequest(
                digitalAsset: $site,
                triggerType: CollectionTriggerType::System,
                requestedBy: $actor,
                bindingIds: [$binding->id],
                requestFamilyIds: [SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION],
                idempotencyKey: 'seo-url-inspection:'.$site->id.':'.now()->format('o-W'),
                context: ['url_inspection_targets' => $targets, 'collection_intent' => 'seo_tasks_url_inspection'],
            ));
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'targets' => count($targets), 'message' => mb_substr($exception->getMessage(), 0, 300)];
        }

        return ['status' => 'queued', 'targets' => count($targets), 'run_id' => (int) $run->id];
    }
}
