<?php

namespace App\Services\SeoTasks;

use App\Jobs\CollectWebsiteInventoryJob;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SeoPlan;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Page inventory guarantee for the SEO plan. A plan that finds no document page cannot tell which pages exist, so
 * it asks for a collection (sitemap + crawl) or a projection rebuild instead of proposing duplicates; once the
 * rebuilt projection has pages, the blocked plan is re-run automatically.
 */
final class SeoInventoryGuard
{
    public const string TRIGGER_INVENTORY_READY = 'inventory_ready';

    private const array ACTIVE_COLLECTION = ['queued', 'running', 'retrying', 'cancellation_requested'];

    /**
     * Called by the plan run when the inventory is empty. Never throws.
     *
     * @return array{empty: true, collection: string, collection_run_id?: int, error?: string}
     */
    public function ensure(DigitalAsset $site): array
    {
        try {
            if (blank($site->primary_url) && blank($site->domain)) {
                return ['empty' => true, 'collection' => 'no_address'];
            }

            $active = CollectionRun::query()->where('digital_asset_id', $site->id)
                ->whereIn('status', self::ACTIVE_COLLECTION)->latest('id')->first();
            if ($active !== null) {
                return ['empty' => true, 'collection' => 'running', 'collection_run_id' => (int) $active->id];
            }

            $hours = SeoTaskConfig::int('inventory.recollect_after_hours', 12);
            $recent = CollectionRun::query()->where('digital_asset_id', $site->id)
                ->whereIn('status', ['completed', 'partial'])
                ->where('created_at', '>=', now()->subHours($hours))
                ->whereHas('datasetRuns', static fn ($query) => $query->where('dataset_contract_id', 'website_url'))
                ->latest('id')->first();
            if ($recent !== null) {
                $projected = WebsiteIntelligenceProjectionRun::query()->where('website_asset_id', $site->id)
                    ->whereIn('status', [WebsiteIntelligenceProjectionRun::STATUS_COMPLETED, WebsiteIntelligenceProjectionRun::STATUS_PARTIAL])
                    ->where('created_at', '>=', $recent->finished_at ?? $recent->created_at)
                    ->exists();
                if ($projected) {
                    // Crawled and projected, still no page: a new crawl now would find the same; the operator checks the site.
                    return ['empty' => true, 'collection' => 'empty_after_crawl', 'collection_run_id' => (int) $recent->id];
                }
                RebuildWebsiteProjectionJob::dispatch(websiteAssetId: (int) $site->id, trigger: 'seo_inventory_guard', triggerCollectionRunId: (int) $recent->id);

                return ['empty' => true, 'collection' => 'rebuilding', 'collection_run_id' => (int) $recent->id];
            }

            CollectWebsiteInventoryJob::dispatch((int) $site->id);

            return ['empty' => true, 'collection' => 'queued'];
        } catch (Throwable $exception) {
            report($exception);

            return ['empty' => true, 'collection' => 'not_started', 'error' => mb_substr($exception->getMessage(), 0, 200)];
        }
    }

    /**
     * Called after a projection rebuild: re-run the site's last plan when it was blocked by an empty inventory and
     * pages exist now. A plan that was itself such a re-run is not repeated (no loop on non-document inventories).
     */
    public function afterProjection(DigitalAsset $site): ?SeoPlan
    {
        try {
            $latest = SeoPlan::query()->where('digital_asset_id', $site->id)->latest('id')->first();
            if ($latest === null
                || $latest->status !== SeoPlan::STATUS_COMPLETED
                || data_get($latest->input_summary, 'inventory.empty') !== true
                || $latest->trigger === self::TRIGGER_INVENTORY_READY) {
                return null;
            }
            if (! app(ServiceScope::class)->isAssetOperational($site->id)
                || ! WebsitePageProfile::query()->where('website_asset_id', $site->id)->exists()) {
                return null;
            }

            return app(SeoPlanRunner::class)->queue($site, null, self::TRIGGER_INVENTORY_READY);
        } catch (ValidationException) {
            return null;
        } catch (Throwable $exception) {
            Log::warning('seo-inventory.replan-failed', ['website_asset_id' => $site->id, 'error' => $exception->getMessage()]);

            return null;
        }
    }
}
