<?php

namespace App\Services\Site;

use App\Jobs\Site\RunSiteOperationJob;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use Illuminate\Support\Facades\Cache;

/**
 * Entry point of the website-screen operations: queues them (heavy queue, one job per bounded batch), runs them from
 * the job and keeps a one-line status per site × operation for the screen.
 */
final class SiteOperations
{
    public const string CATEGORIZE = 'categorize';

    public const string SERVICE_PAGES = 'service_pages';

    public const string CLUSTER_PAGES = 'cluster_pages';

    public const string SUMMARIES = 'summaries';

    public const string URL_ANALYSIS = 'url_analysis';

    public const string APPLY_CHANGE = 'apply_change';

    public const string STANDARD = 'standard';

    public const string WEEKLY_CONTENT = 'weekly_content';

    public const string DISCOVERY = 'content_discovery';

    public const string WRITE_ARTICLE = 'write_article';

    /** Weekly refresh: new pages' categories, service ↔ page, cluster ↔ page, summaries of changed pages in use. */
    public const string WEEKLY_REFRESH = 'weekly_refresh';

    /** Pages per URL analysis job (each page is one AI call). */
    public const int URL_BATCH = 3;

    public const array LABELS = [
        self::CATEGORIZE => 'Sınıflandırma', self::SERVICE_PAGES => 'Hizmet ↔ sayfa', self::CLUSTER_PAGES => 'Küme ↔ sayfa',
        self::SUMMARIES => 'Sayfa özetleri', self::URL_ANALYSIS => 'URL analizi', self::APPLY_CHANGE => 'AI ile yap', self::STANDARD => 'Standart önerisi',
        self::WEEKLY_CONTENT => 'Haftalık içerik', self::DISCOVERY => 'Fırsat keşfi', self::WRITE_ARTICLE => 'Taslak', self::WEEKLY_REFRESH => 'Haftalık yenileme',
    ];

    public function __construct(
        private readonly PageCategorizer $categorizer,
        private readonly ServicePageMapper $servicePages,
        private readonly ClusterPageMapper $clusterPages,
        private readonly BrandMemoryService $memory,
        private readonly UrlAnalyzer $analyzer,
        private readonly ChangeApplier $changes,
        private readonly ScopedStandards $standards,
        private readonly ContentPlanner $content,
    ) {}

    /** @param  array<string, mixed>  $params */
    public static function dispatch(int $siteId, string $operation, array $params = []): void
    {
        self::putStatus($siteId, $operation, ['status' => 'running'], $params);
        RunSiteOperationJob::dispatch($siteId, $operation, $params);
    }

    /**
     * URL analysis in bounded jobs.
     *
     * @param  list<int>  $pageIds
     */
    public static function dispatchUrlAnalysis(int $siteId, array $pageIds): int
    {
        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        foreach (array_chunk(array_slice($pageIds, 0, 60), self::URL_BATCH) as $chunk) {
            RunSiteOperationJob::dispatch($siteId, self::URL_ANALYSIS, ['page_ids' => $chunk]);
        }
        self::putStatus($siteId, self::URL_ANALYSIS, ['status' => 'running', 'pages' => min(60, count($pageIds))]);

        return min(60, count($pageIds));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function run(DigitalAsset $site, string $operation, array $params = []): array
    {
        $brand = SiteScope::brandOf($site);
        $suggestion = isset($params['suggestion_id']) ? Suggestion::query()->where('brand_id', $site->brand_id)->find((int) $params['suggestion_id']) : null;

        return match ($operation) {
            self::CATEGORIZE => $this->categorizer->categorize($site, (bool) ($params['only_new'] ?? false)),
            self::SERVICE_PAGES => $this->servicePages->map($site),
            self::CLUSTER_PAGES => $this->clusterPages->refresh($site),
            self::SUMMARIES => $brand !== null ? $this->memory->summarize($brand, $this->pagesInUse($site)) : ['status' => 'no_brand'],
            self::URL_ANALYSIS => $this->analyzer->analyze($site, array_map('intval', (array) ($params['page_ids'] ?? []))),
            self::APPLY_CHANGE => $suggestion !== null ? $this->changes->prepare($suggestion) : ['status' => 'no_suggestion'],
            self::STANDARD => $suggestion !== null ? $this->standards->propose($suggestion) : ['status' => 'no_suggestion'],
            self::WEEKLY_CONTENT => $this->content->weekly($site),
            self::DISCOVERY => $this->content->discover($site),
            self::WRITE_ARTICLE => $suggestion !== null ? $this->content->writeArticle($suggestion) : ['status' => 'no_suggestion'],
            self::WEEKLY_REFRESH => $this->weeklyRefresh($site),
            default => ['status' => 'unknown_operation'],
        };
    }

    /** @return array<string, mixed> */
    private function weeklyRefresh(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $this->memory->refreshProfile($brand);
        SiteMetrics::forgetPageTotals((int) $site->id);

        return [
            'status' => 'ready',
            'categorize' => $this->categorizer->categorize($site, onlyNew: true)['status'],
            'service_pages' => $this->servicePages->map($site)['status'],
            'cluster_pages' => $this->clusterPages->refresh($site)['status'],
            'summaries' => $this->memory->summarize($brand, $this->pagesInUse($site))['status'],
        ];
    }

    /**
     * Pages used in analysis (cluster targets, service pages, pages with suggestions): their summaries are kept fresh.
     *
     * @return list<int>
     */
    public function pagesInUse(DigitalAsset $site): array
    {
        $ids = Page::query()->where('website_asset_id', $site->id)->where(fn ($q) => $q
            ->whereIn('id', BrandClusterPage::query()->where('website_asset_id', $site->id)->whereNotNull('page_id')->select('page_id'))
            ->orWhereIn('id', OfferingPage::query()->whereNotNull('brand_offering_id')->select('page_id'))
            ->orWhereIn('id', Suggestion::query()->whereNotNull('page_id')->where('brand_id', (int) $site->brand_id)->select('page_id')))
            ->orderBy('id')->limit(200)->pluck('id');

        return $ids->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $params
     */
    public static function putStatus(int $siteId, string $operation, array $result, array $params = []): void
    {
        Cache::put(self::key($siteId, $operation, $params), $result + ['at' => now()->toIso8601String()], now()->addDays(2));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public static function status(int $siteId, string $operation, array $params = []): ?array
    {
        $value = Cache::get(self::key($siteId, $operation, $params));

        return is_array($value) ? $value : null;
    }

    /** One-line Turkish status. @param  array<string, mixed>|null  $status */
    public static function line(?array $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match ((string) ($status['status'] ?? '')) {
            'running' => 'çalışıyor…',
            'ready' => 'tamam',
            'not_operational' => 'pasif müşteri: AI çalışmaz',
            'no_provider', 'ai_no_provider' => 'AI bağlı değil',
            'no_services' => 'onaylı hizmet yok',
            'no_clusters' => 'onaylı küme yok',
            'no_queries' => 'sorgu yok',
            'no_brand' => 'marka / sektör yok',
            'blocked' => 'uyum kuralına takıldı',
            'invalid' => 'AI çıktısı doğrulanamadı',
            'not_applicable' => 'bu öneri için uygulanamaz',
            default => 'hata',
        };
    }

    /** @param  array<string, mixed>  $params */
    private static function key(int $siteId, string $operation, array $params): string
    {
        $subject = isset($params['suggestion_id']) ? ':'.$params['suggestion_id'] : '';

        return 'site-op:'.$siteId.':'.$operation.$subject;
    }
}
