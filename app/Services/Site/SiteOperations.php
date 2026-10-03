<?php

namespace App\Services\Site;

use App\Jobs\Site\RunSiteOperationJob;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandDossier;
use App\Services\Queries\QueryPipeline;
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

    /** "Kümeleri içerikle karşılaştır": AI match + gaps of every cluster row (ClusterAudit). */
    public const string CLUSTER_AUDIT = 'cluster_audit';

    /**
     * İçerik fikirleri "AI ile geliştir": a missing-topic suggestion from the idea row's gaps and recipe, prepared by
     * "AI ile yap" (params: kind main | extra, id).
     */
    public const string FIX_GAPS = 'fix_gaps';

    /** İçerik fikirleri "AI ile üret": a content plan item from the idea with no page, its article written (params: kind, id). */
    public const string PRODUCE = 'produce';

    /** İçerik fikirleri "Yeniden keşfet": match + gaps of one idea row on the stored pages (params: kind, id). */
    public const string REDISCOVER = 'rediscover';

    /** İçerik fikirleri "SEO analizi": the recipe of one idea row (params: kind, id). */
    public const string RECIPE = 'recipe';

    /** Weekly refresh: new pages' categories, service ↔ page, cluster ↔ page, summaries of changed pages in use. */
    public const string WEEKLY_REFRESH = 'weekly_refresh';

    /**
     * After "Otomatik kur" approval: new pages' categories, service ↔ page, cluster rows (rules) and the brand's target
     * queries, so the website screen fills at once instead of after the weekly refresh.
     */
    public const string SETUP = 'setup';

    /** Pages per URL analysis job (each page is one AI call). */
    public const int URL_BATCH = 3;

    public const array LABELS = [
        self::CATEGORIZE => 'Sınıflandırma', self::SERVICE_PAGES => 'Hizmet ↔ sayfa', self::CLUSTER_PAGES => 'Küme ↔ sayfa',
        self::SUMMARIES => 'Sayfa özetleri', self::URL_ANALYSIS => 'URL analizi', self::APPLY_CHANGE => 'AI ile yap', self::STANDARD => 'Standart önerisi',
        self::WEEKLY_CONTENT => 'Haftalık içerik', self::DISCOVERY => 'Fırsat keşfi', self::WRITE_ARTICLE => 'Taslak', self::WEEKLY_REFRESH => 'Haftalık yenileme',
        self::CLUSTER_AUDIT => 'Eşleştir', self::FIX_GAPS => 'AI ile geliştir', self::PRODUCE => 'AI ile üret', self::REDISCOVER => 'Yeniden keşfet',
        self::RECIPE => 'SEO analizi', self::SETUP => 'Kurulum sonrası hazırlık',
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
        private readonly ClusterAudit $audit,
        private readonly ContentRecipe $recipes,
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
        $subject = isset($params['kind'], $params['id']) ? ContentIdeaSubject::find((int) $site->id, (string) $params['kind'], (int) $params['id']) : null;

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
            self::SETUP => $this->afterSetup($site, (bool) ($params['unattended'] ?? false)),
            self::CLUSTER_AUDIT => $this->audit->run($site, continueOnly: isset($params['part'])),
            self::FIX_GAPS => $subject !== null ? $this->fixGaps($subject) : ['status' => 'no_row'],
            self::PRODUCE => $subject !== null ? $this->content->produce($subject) : ['status' => 'no_row'],
            self::REDISCOVER => $subject === null ? ['status' => 'no_row'] : ($subject->kind === ContentIdeaSubject::MAIN
                ? $this->audit->rediscover($subject->row) : $this->audit->rediscoverIdea($subject->row)),
            self::RECIPE => $subject !== null ? $this->recipes->build($subject) : ['status' => 'no_row'],
            default => ['status' => 'unknown_operation'],
        };
    }

    /**
     * "AI ile geliştir": the idea row's gaps and stored recipe become one missing-topic suggestion on its page (one
     * per row, refreshed), then "AI ile yap" prepares the new version: compared side by side under Öneriler, Admin
     * approves ("Güncelle"), WordPress gets it (ADR-070, undoable).
     *
     * @return array<string, mixed>
     */
    private function fixGaps(ContentIdeaSubject $subject): array
    {
        $row = $subject->row;
        $gaps = $subject->gaps();
        $steps = (array) data_get($row->recipe, 'steps', []);
        if ($row->page_id === null || ($gaps === [] && $steps === [])) {
            return ['status' => 'no_gaps'];
        }
        $key = $subject->kind === ContentIdeaSubject::MAIN ? 'cluster-gaps' : 'idea-gaps';
        $fingerprint = hash('sha256', implode('|', [$row->brand_id, $key, $row->id]));
        $suggestion = Suggestion::query()->where('brand_id', $row->brand_id)->where('fingerprint', $fingerprint)->first() ?? new Suggestion;
        $lines = $gaps !== [] ? array_map(fn (array $g): string => (string) $g['text'], $gaps) : array_map(fn (array $s): string => (string) $s['action'], $steps);
        $suggestion->forceFill([
            'brand_id' => $row->brand_id, 'channel' => 'search', 'decision_key' => 'site.cluster_gaps', 'fingerprint' => $fingerprint,
            'material_hash' => hash('sha256', json_encode([$gaps, $steps]) ?: ''), 'title' => mb_substr('Geliştir: '.$subject->title(), 0, 160),
            'reason' => mb_substr(implode(' · ', $lines), 0, 240), 'priority' => 2,
            'evidence' => $gaps !== [] ? array_map(fn (array $g): array => ['kind' => 'gap', 'value' => (string) $g['text'], 'source' => 'içerik fikri · '.$g['kind']], $gaps)
                : array_map(fn (array $s): array => ['kind' => 'recipe', 'value' => (string) $s['action'], 'source' => 'SEO analizi'], $steps),
            'action_type' => 'missing_topic', 'target_type' => 'page', 'target_id' => (int) $row->page_id, 'page_id' => (int) $row->page_id,
            'cluster_id' => (int) $subject->cluster->id, 'status' => Suggestion::OPEN, 'first_seen_at' => $suggestion->first_seen_at ?? now(), 'last_seen_at' => now(),
            'applied_at' => null,
            'action' => array_merge(array_diff_key((array) $suggestion->action, array_flip(['proposal', 'writes'])), ['site_id' => (int) $subject->site->id, 'gaps' => $gaps,
                'recipe' => array_filter(['steps' => $steps, 'seo_title' => data_get($row->recipe, 'seo_title'), 'meta_description' => data_get($row->recipe, 'meta_description')]),
                'idea_title' => $subject->idea?->title] + $subject->params() + ($subject->kind === ContentIdeaSubject::MAIN ? ['row_id' => (int) $row->id] : [])),
        ])->save();

        return ['suggestion_id' => (int) $suggestion->id] + $this->changes->prepare($suggestion);
    }

    /**
     * @param  bool  $unattended  started by the nightly upkeep (no operator click)
     * @return array<string, mixed>
     */
    private function afterSetup(DigitalAsset $site, bool $unattended = false): array
    {
        SiteMetrics::forgetPageTotals((int) $site->id);
        // Nightly upkeep never sends hundreds of pages to AI on its own: too many → Eksikler asks the operator first.
        $aiLimit = $unattended ? PageCategorizer::UNATTENDED_AI_LIMIT : null;
        $result = ['status' => 'ready', 'categorize' => $this->categorizer->categorize($site, onlyNew: true, aiLimit: $aiLimit)['status']];
        if (SiteScope::aiAllowed(SiteScope::brandOf($site))) {
            $result['service_pages'] = $this->servicePages->map($site)['status'];
        }
        $result['cluster_pages'] = $this->clusterPages->refresh($site, judge: false)['status'];
        if ($site->brand_id !== null) {
            $result['targets'] = app(QueryPipeline::class)->brandTargets((int) $site->brand_id);
            if (($brand = Brand::query()->find($site->brand_id)) !== null) {
                app(BrandDossier::class)->build($brand);
            }
        }
        // Site akışı: the cluster ↔ page step follows at once when it is due (WordPress paired, operational brand).
        $result['flow'] = SiteFlow::advance($site, setup: false);

        return $result;
    }

    /** @return array<string, mixed> */
    private function weeklyRefresh(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        // Rule categories need no AI: new pages get one even for a passive brand (the AI pass is skipped there).
        $categorize = $this->categorizer->categorize($site, onlyNew: true)['status'];
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'categorize' => $categorize];
        }
        $this->memory->refreshProfile($brand);
        SiteMetrics::forgetPageTotals((int) $site->id);

        return [
            'status' => 'ready',
            'categorize' => $categorize,
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
            'queued' => 'Claude bekleniyor (MCP); sonuç gelince devam eder',
            'timeout' => 'süre aşıldı; kalan kısım sonra devam eder',
            'stalled' => 'çok parçaya bölündü, durduruldu; tekrar başlatın',
            'ready' => 'tamam',
            'not_operational' => 'pasif müşteri: AI çalışmaz',
            'no_provider', 'ai_no_provider' => 'AI bağlı değil',
            'no_services' => 'onaylı hizmet yok',
            'no_clusters' => 'onaylı küme yok',
            'no_queries' => 'sorgu yok',
            'no_brand' => 'marka / sektör yok',
            'no_gaps' => 'giderilecek eksik yok',
            'has_page' => 'sayfası var',
            'blocked' => 'uyum kuralına takıldı',
            'invalid' => 'AI çıktısı doğrulanamadı',
            'not_applicable' => 'bu öneri için uygulanamaz',
            default => 'hata',
        };
    }

    /** @param  array<string, mixed>  $params */
    private static function key(int $siteId, string $operation, array $params): string
    {
        $subject = isset($params['suggestion_id']) ? ':'.$params['suggestion_id'] : (isset($params['kind'], $params['id']) ? ':'.$params['kind'].'-'.$params['id'] : '');

        return 'site-op:'.$siteId.':'.$operation.$subject;
    }
}
