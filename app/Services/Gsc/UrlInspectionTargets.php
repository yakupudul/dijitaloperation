<?php

namespace App\Services\Gsc;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleEligibilityGuard;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Which pages of a site to send to the Search Console URL Inspection API today: the API answers one URL at a time
 * (property quota ~2000 a day), so each day takes a small batch — service pages first, then the pages Google shows most,
 * then the rest of the inventory — skipping URLs inspected in the last INTERVAL_DAYS. Over a few weeks every page gets
 * its Google index state (Teknik SEO › Google'ın bildirdikleri). Only URLs inside the bound property are returned.
 */
final class UrlInspectionTargets
{
    public const int INTERVAL_DAYS = 14;

    public function __construct(
        private readonly GscSpecialistBindingResolver $bindings,
        private readonly SearchConsoleEligibilityGuard $guard,
    ) {}

    /** Today's batch: a URL Inspection collection run for the site (no run when nothing is due or GSC is not bound). */
    public function start(DigitalAsset $site): ?CollectionRun
    {
        $batch = $this->for($site, max(1, (int) config('moxdop-gsc-collector.url_inspection_max_targets_per_run', 25)));
        if ($batch['targets'] === []) {
            return null;
        }

        return app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $site,
            triggerType: CollectionTriggerType::System,
            requestFamilyIds: [SearchConsoleRequestFamilyCatalog::FAMILY_URL_INSPECTION],
            providerSources: ['SEARCH_CONSOLE'],
            idempotencyKey: 'gsc-inspect:'.$site->id.':'.now()->toDateString(),
            context: ['url_inspection_targets' => $batch['targets'], 'collection_intent' => 'gsc_url_inspection_daily',
                'collection_intent_label' => 'Search Console URL denetimi (günlük)'],
        ));
    }

    /** @return array{site_url: ?string, targets: list<string>} */
    public function for(DigitalAsset $site, int $limit): array
    {
        $binding = $this->bindings->resolve((string) $site->id);
        if (! $binding->isReal() || $binding->externalResourceId === null || ! filled($binding->siteUrl)) {
            return ['site_url' => null, 'targets' => []];
        }
        $siteUrl = (string) $binding->siteUrl;
        $recent = Schema::hasTable('gsc_url_inspection_snapshot')
            ? DB::table('gsc_url_inspection_snapshot')->where(fn ($q) => $q->where('digital_asset_id', $site->id)->orWhere('external_resource_id', $binding->externalResourceId))
                ->where('inspected_at', '>=', now()->subDays(self::INTERVAL_DAYS))->pluck('page')->map(fn ($u): string => SeoText::urlKey((string) $u))->flip()->all()
            : [];

        // Service pages tied to a brand service first, then the other service pages.
        $service = Page::query()->where('website_asset_id', $site->id)->where('category', 'hizmet')
            ->orderByRaw('CASE WHEN id IN (SELECT page_id FROM offering_pages) THEN 0 ELSE 1 END')->orderBy('id')->pluck('url')->all();
        $shown = DB::table('gsc_query_page_daily')->where('external_resource_id', $binding->externalResourceId)->where('search_type', 'web')
            ->where('reporting_date', '>=', now()->subDays(28)->toDateString())->groupBy('page')->orderByRaw('sum(impressions) desc')->limit($limit * 4)->pluck('page')->all();
        $inventory = Page::query()->where('website_asset_id', $site->id)->orderBy('id')->limit(2000)->pluck('url')->all();

        $out = [];
        foreach ([...$service, ...$shown, ...$inventory] as $url) {
            $url = (string) $url;
            $key = SeoText::urlKey($url);
            if ($url === '' || isset($recent[$key]) || isset($out[$key]) || ! str_starts_with($url, 'http')) {
                continue;
            }
            try {
                $this->guard->assertInspectionUrlBelongsToProperty($siteUrl, $url);
            } catch (Throwable) {
                continue;
            }
            $out[$key] = $url;
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['site_url' => $siteUrl, 'targets' => array_values($out)];
    }
}
