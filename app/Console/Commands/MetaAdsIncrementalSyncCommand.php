<?php

namespace App\Console\Commands;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Console\Command;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;

/**
 * Hourly incremental Meta history sync. For every available Meta Ad Account that has
 * already been imported (coverage complete/partial), queues an asset-scoped refresh so
 * only the correction window through today is re-fetched. Accounts already importing
 * or refreshing are skipped, and dispatches are capped/spaced to respect provider limits.
 */
class MetaAdsIncrementalSyncCommand extends Command
{
    protected $signature = 'meta-ads:incremental-sync {--limit=50 : Maximum number of accounts to queue per run}';

    protected $description = 'Queue incremental Meta history refreshes for imported, bound Meta Ad Accounts';

    public function handle(AsyncOperationService $async): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $coverageResourceIds = MetaAdsHistoryCoverage::query()
            ->where('data_layer', MetaAdsHistoryCoverage::LAYER_DAILY_FACTS)
            ->whereIn('status', [MetaAdsHistoryCoverage::STATUS_COMPLETE, MetaAdsHistoryCoverage::STATUS_PARTIAL])
            ->pluck('core_external_resource_id')
            ->unique()
            ->all();

        if ($coverageResourceIds === []) {
            $this->info('No imported Meta Ad Accounts to refresh.');

            return self::SUCCESS;
        }

        $resources = CoreExternalResource::query()
            ->whereIn('id', $coverageResourceIds)
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaHistoricalImportService::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->orderBy('id')
            ->get();

        $queued = 0;
        $skipped = 0;

        foreach ($resources as $resource) {
            if ($queued >= $limit) {
                break;
            }

            $binding = CoreAssetBinding::query()
                ->where('external_resource_id', $resource->id)
                ->where('capability', MetaHistoricalImportService::RESOURCE_TYPE)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->latest('id')
                ->first();

            if ($binding === null || $binding->digital_asset_id === null) {
                // Unbound accounts have no asset-scoped surface; leave them for the
                // Integration-scoped "Import Meta history" flow.
                $skipped++;

                continue;
            }

            $assetId = (int) $binding->digital_asset_id;

            // Skip accounts with an in-flight import or refresh so we never overlap.
            if ($async->activeRun($assetId, AsyncOperationTypes::META_HISTORY_REFRESH) !== null
                || $async->activeRun($assetId, AsyncOperationTypes::META_HISTORY_IMPORT) !== null) {
                $skipped++;

                continue;
            }

            $asset = $binding->digitalAsset;
            if ($asset === null) {
                $skipped++;

                continue;
            }

            $result = $async->queueMetaHistoryRefresh($asset);
            if (($result['queued'] ?? false) === true) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        $this->info("Queued {$queued} Meta refresh job(s); skipped {$skipped}.");

        return self::SUCCESS;
    }
}
