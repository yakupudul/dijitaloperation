<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts a public website collection (homepage, robots, sitemap, crawl) for a site whose page inventory is empty,
 * so the SEO plan can judge which pages exist. Queued by SeoInventoryGuard; the plan re-runs after the projection.
 */
final class CollectWebsiteInventoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $websiteAssetId) {}

    public function uniqueId(): string
    {
        return 'website-inventory-collection:'.$this->websiteAssetId;
    }

    public function handle(WebsiteCollectionOrchestrator $orchestrator): void
    {
        $site = DigitalAsset::query()->where('type', 'website')->find($this->websiteAssetId);
        if ($site === null) {
            return;
        }

        try {
            $orchestrator->start(asset: $site, context: [
                'trigger' => 'seo_plan.inventory_missing',
                'idempotency_key' => 'seo-inventory:'.$site->id.':'.now()->format('YmdH'),
                'collection_intent' => 'seo_inventory_collection',
                'collection_intent_label' => 'SEO plan page inventory',
            ]);
        } catch (Throwable $exception) {
            // Already running, not served or not admitted: the guard sees the state on the next plan.
            Log::info('seo-inventory.collection-not-started', ['website_asset_id' => $site->id, 'error' => $exception->getMessage()]);
        }
    }
}
