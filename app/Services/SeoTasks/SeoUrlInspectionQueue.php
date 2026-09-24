<?php

namespace App\Services\SeoTasks;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\SiteFixItem;
use App\Models\User;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // 1.4.1: pages that changed this week go first.
        $targets = [...$this->recentlyChanged($site, now()->subDays(7), now()), ...$targets];
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

        return $this->start($site, $binding, $targets, 'seo-url-inspection:'.$site->id.':'.now()->format('o-W'), 'seo_tasks_url_inspection', $actor);
    }

    /**
     * 1.4.1: daily, inspect the pages that changed one to three days ago (WordPress activity or an applied fix), so the
     * operator sees whether Google already picked the change up. Read-only; nothing is sent to Google but the query.
     *
     * @return array{status: string, targets: int, run_id?: int, message?: string}
     */
    public function queueChanged(DigitalAsset $site): array
    {
        $max = min(SeoTaskConfig::int('indexing.inspection_max_targets', 20), (int) config('moxdop-gsc-collector.url_inspection_max_targets_per_run', 25));
        $targets = array_slice($this->recentlyChanged($site, now()->subDays(3), now()->subDay()), 0, max(0, $max));
        if ($targets === []) {
            return ['status' => 'nothing_to_inspect', 'targets' => 0];
        }
        $binding = CoreAssetBinding::query()->where('digital_asset_id', $site->id)->where('capability', 'search_console')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->first();
        if ($binding === null) {
            return ['status' => 'not_bound', 'targets' => 0];
        }

        return $this->start($site, $binding, $targets, 'changed-url-inspection:'.$site->id.':'.now()->format('Y-m-d'), 'changed_pages_url_inspection', null);
    }

    /**
     * Public URLs of this site changed in the window: WordPress activity events and applied site fixes.
     *
     * @return list<string>
     */
    public function recentlyChanged(DigitalAsset $site, CarbonInterface $from, CarbonInterface $to): array
    {
        $host = strtolower((string) parse_url((string) ($site->primary_url ?: 'https://'.$site->domain), PHP_URL_HOST));
        $urls = [];
        if (Schema::hasTable('website_connector_events')) {
            DB::table('website_connector_events')->where('digital_asset_id', $site->id)->whereBetween('received_at', [$from, $to])
                ->where(fn ($q) => $q->where('type', 'like', 'content.%')->orWhere('type', 'seo.updated'))
                ->whereNotIn('type', ['content.trashed', 'content.deleted', 'content.unpublished'])
                ->orderByDesc('id')->limit(200)->pluck('payload')
                ->each(function ($payload) use (&$urls): void {
                    $url = data_get(json_decode((string) $payload, true), 'url');
                    if (is_string($url) && $url !== '') {
                        $urls[] = $url;
                    }
                });
        }
        SiteFixItem::query()->where('digital_asset_id', $site->id)->where('status', 'applied')->whereBetween('updated_at', [$from, $to])
            ->whereNotIn('type', ['alt_text', 'redirect', 'new_page'])->whereNotNull('url')->orderByDesc('updated_at')->limit(100)->pluck('url')
            ->each(function ($url) use (&$urls): void {
                $urls[] = (string) $url;
            });

        return array_values(array_unique(array_filter($urls, fn (string $url): bool => str_starts_with($url, 'http')
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === $host)));
    }

    /**
     * @param  list<string>  $targets
     * @return array{status: string, targets: int, run_id?: int, message?: string}
     */
    private function start(DigitalAsset $site, CoreAssetBinding $binding, array $targets, string $key, string $intent, ?User $actor): array
    {
        try {
            $run = $this->starter->start(new StartCollectionRequest(
                digitalAsset: $site,
                triggerType: CollectionTriggerType::System,
                requestedBy: $actor,
                bindingIds: [$binding->id],
                requestFamilyIds: [SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION],
                idempotencyKey: $key,
                context: ['url_inspection_targets' => $targets, 'collection_intent' => $intent],
            ));
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'targets' => count($targets), 'message' => mb_substr($exception->getMessage(), 0, 300)];
        }

        return ['status' => 'queued', 'targets' => count($targets), 'run_id' => (int) $run->id];
    }
}
