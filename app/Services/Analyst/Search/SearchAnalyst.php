<?php

namespace App\Services\Analyst\Search;

use App\Models\AnalystDecision;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\TopicCluster;
use App\Models\User;
use App\Services\Analyst\AbstractChannelAnalyst;
use App\Services\Analyst\AnalystPack;
use App\Services\ContentStudio\ContentIdeaPlanner;
use App\Services\ContentStudio\ContentStudio;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\IndustryOptions;
use Illuminate\Validation\ValidationException;

/**
 * Arama: be found for every query people in the brand's cities / districts make for its services (organic + AI
 * search surfaces through the same standards). Inputs (rule code, stored data only): services × service areas,
 * core queries of the one query store with Search Console metrics, library clusters with SERP page-type evidence,
 * the website topic map verdicts, URL verdicts with GA4 key events, failing critical standards, open SEO tasks
 * (candidate facts, not a separate screen) and inventory / freshness.
 */
final class SearchAnalyst extends AbstractChannelAnalyst
{
    public const array ACTIONS = [
        'open_content_studio_idea' => ['label' => 'Stüdyoda aç', 'kind' => 'link', 'targets' => ['tc']],
        'prepare_article' => ['label' => 'Yazıyı hazırla', 'kind' => 'run', 'targets' => ['tc']],
        'prepare_page_update' => ['label' => 'Güncelleme taslağı hazırla', 'kind' => 'run', 'targets' => ['tc']],
        'open_fix' => ['label' => 'Düzeltmeyi aç', 'kind' => 'link', 'targets' => ['u', 'std', 'task']],
        'merge_redirect' => ['label' => 'Birleştir / yönlendir', 'kind' => 'link', 'targets' => ['u', 'tc']],
        'set_service_area' => ['label' => 'Bölgeyi ayarla', 'kind' => 'link', 'targets' => ['area', 'cov', 'svc']],
        'map_service' => ['label' => 'Hizmeti eşle', 'kind' => 'link', 'targets' => ['q', 'svc', 'lc']],
    ];

    public function __construct(private readonly SearchFacts $facts) {}

    public function channel(): string
    {
        return 'search';
    }

    public function allowedActions(): array
    {
        return self::ACTIONS;
    }

    public function instructions(): string
    {
        return <<<'TXT'
Channel: organic Google search (and AI search surfaces, which read the same pages). Goal: the brand is found for
every query people in its service areas (cities / districts) make for its services. Competitor brands, product
brands and irrelevant queries are already removed. Think like a senior local-SEO consultant:
- uncovered service × area cells and topics without a page (verdict "new") → open_content_studio_idea or
  prepare_article (target: the topic "tc:" id); weakly covered topics with an owner page (verdict "strengthen") →
  prepare_page_update;
- pages with critical failing standards, "fix" verdicts on pages with clicks / key events → open_fix;
- two URLs splitting a topic (verdict "merge") → merge_redirect;
- a missing service area or a query without the right service → set_service_area / map_service.
Prefer high-impression queries at positions 4–20, pages that convert (key_events) and the brand's priority services.
TXT;
    }

    public function buildPack(Brand $brand): AnalystPack
    {
        $site = $this->facts->website($brand);
        $context = [
            'brand' => $brand->name,
            'sector' => implode(', ', array_filter(array_map(fn (string $c): string => (string) IndustryOptions::label($c), $brand->sectorCodes()))),
            'window_days' => SearchFacts::WINDOW_DAYS, 'top_n' => SearchFacts::TOP_N,
            'website' => $site !== null ? (string) ($site->domain ?: $site->primary_url) : null,
        ];
        if ($site === null) {
            return AnalystPack::missing('search', (int) $brand->id, (string) $this->facts->missing($brand, null), [], $context);
        }
        $stats = $this->stats($brand, $site);
        $missing = $this->facts->missing($brand, $site);
        if ($missing !== null) {
            return AnalystPack::missing('search', (int) $brand->id, $missing, $stats, $context);
        }

        $matrix = $this->facts->matrix($brand, $site);
        $sections = [];
        foreach ($matrix['services'] as $id => $name) {
            $sections['services']['svc:'.$id] = ['name' => $name];
        }
        foreach ($matrix['areas'] as $id => $name) {
            $sections['areas']['area:'.$id] = ['name' => $name];
        }
        foreach ($matrix['cells'] as $key => $cell) {
            $sections['coverage']['cov:'.$key] = [
                'service' => $matrix['services'][$cell['service_id']], 'area' => $matrix['areas'][$cell['area_id']],
                'status' => $cell['status'], 'position' => $cell['position'], 'path' => $cell['url'] !== null ? SeoText::urlPath($cell['url']) : null,
            ];
        }
        foreach ($this->facts->topics($site) as $topic) {
            $detail = (array) $topic->verdict_detail;
            $sections['topics']['tc:'.$topic->id] = [
                'label' => $topic->label, 'service' => $topic->offering?->displayName(), 'page_type' => $topic->page_type, 'verdict' => $topic->verdict,
                'coverage' => $topic->coverage, 'owner' => $topic->owner_url !== null ? SeoText::urlPath((string) $topic->owner_url) : null,
                'owner_position' => $topic->owner_position !== null ? round((float) $topic->owner_position, 1) : null,
                'impressions' => (int) $topic->impressions, 'clicks' => (int) $topic->clicks, 'queries' => (int) $topic->query_count,
                'missing' => array_slice(array_values((array) ($detail['missing_queries'] ?? [])), 0, 5),
            ];
        }
        foreach ($this->facts->libraryClusters($brand) as $cluster) {
            $evidence = is_string($cluster->serp_evidence ?? null) ? (array) json_decode($cluster->serp_evidence, true) : [];
            $sections['clusters']['lc:'.$cluster->id] = [
                'name' => (string) $cluster->name, 'service' => $cluster->offering_id !== null ? ($matrix['services'][$cluster->offering_id] ?? null) : null,
                'page_type' => $cluster->page_decision ?? null, 'decided_by' => $cluster->decision_source ?? null, 'head_query' => $cluster->head_query ?? null,
                'serp' => (array) ($evidence['counts'] ?? []), 'impressions' => $cluster->brand_impressions, 'queries' => $cluster->brand_queries,
            ];
        }
        foreach ($this->facts->coreQueries($brand)->with('offering.primaryName', 'offering.catalogItem.primaryName')->orderByDesc('value_score')->orderByDesc('gsc_impressions')->limit(150)->get() as $query) {
            $sections['queries']['q:'.$query->id] = [
                'text' => (string) $query->query, 'service' => $query->offering?->displayName(), 'area' => $query->brand_service_area_id !== null ? ($matrix['areas'][$query->brand_service_area_id] ?? null) : null,
                'impressions' => (int) $query->gsc_impressions, 'clicks' => (int) $query->gsc_clicks,
                'position' => $query->gsc_position !== null ? round((float) $query->gsc_position, 1) : null, 'ads_clicks' => (int) $query->ads_clicks,
            ];
        }
        foreach ($this->facts->urls($site) as $verdict) {
            $sections['urls'][self::urlRef((string) $verdict->url_hash)] = [
                'path' => (string) $verdict->path, 'verdict' => $verdict->verdict, 'severity' => $verdict->severity, 'reason' => mb_substr((string) $verdict->reason, 0, 140),
                'clicks' => $verdict->clicks, 'impressions' => $verdict->impressions, 'position' => $verdict->position !== null ? round($verdict->position, 1) : null,
                'key_events' => $verdict->key_events, 'indexed' => $verdict->indexed,
            ];
        }
        foreach ($this->facts->failingStandards($site) as $id => $standard) {
            $sections['standards']['std:'.$id] = $standard;
        }
        foreach ($this->facts->openTasks($site) as $task) {
            $sections['seo_tasks']['task:'.$task->id] = [
                'title' => mb_substr((string) $task->title, 0, 140), 'type' => $task->type->value, 'severity' => $task->severity,
                'target' => filled($task->target_url) ? SeoText::urlPath((string) $task->target_url) : null,
                'extra_clicks' => $task->estimated_extra_clicks !== null ? (int) round($task->estimated_extra_clicks) : null,
            ];
        }
        $sections['inventory']['inv:site'] = $this->facts->inventory($site);

        return (new AnalystPack('search', (int) $brand->id, $context, $stats, $sections))->trimTo();
    }

    /**
     * Durum (4–6 numbers), computed by rule code.
     *
     * @return list<array<string, mixed>>
     */
    public function stats(Brand $brand, DigitalAsset $site): array
    {
        $clicks = $this->facts->organicClicks($site);
        $matrix = $this->facts->matrix($brand, $site);
        $queries = $this->facts->queryCoverage($brand);
        $blockers = count($this->facts->failingStandards($site));
        $opportunities = $this->facts->contentOpportunities($site);
        $n = fn (int|float $v): string => number_format((float) $v, 0, ',', '.');

        return [
            ['id' => 'organic_clicks', 'label' => 'Organik tıklama (28g)', 'value' => $clicks['current'], 'display' => $n($clicks['current']),
                'delta_pct' => $clicks['delta_pct'], 'previous' => $clicks['previous']],
            ['id' => 'service_area_coverage', 'label' => 'Hizmet × bölge', 'value' => $matrix['pct'], 'display' => $matrix['pct'] === null ? '—' : '%'.$matrix['pct'],
                'note' => $matrix['covered'].'/'.$matrix['total'], 'covered' => $matrix['covered'], 'total' => $matrix['total']],
            ['id' => 'query_coverage', 'label' => 'Sorgu kapsama', 'value' => $queries['pct'], 'display' => $queries['pct'] === null ? '—' : '%'.$queries['pct'],
                'note' => $queries['ranking'].'/'.$queries['total'].' ilk 10', 'ranking' => $queries['ranking'], 'total' => $queries['total']],
            ['id' => 'technical_blockers', 'label' => 'Teknik blokaj', 'value' => $blockers, 'display' => (string) $blockers],
            ['id' => 'content_opportunities', 'label' => 'İçerik fırsatı', 'value' => $opportunities, 'display' => (string) $opportunities],
        ];
    }

    public function presentAction(AnalystDecision $decision): ?array
    {
        $spec = self::ACTIONS[$decision->action_type] ?? null;
        if ($spec === null) {
            return null;
        }
        $target = (string) ($decision->action_params['target'] ?? '');
        $fact = (array) ($decision->action_params['_target'] ?? []);
        [$prefix, $id] = array_pad(explode(':', $target, 2), 2, '');
        $site = $this->facts->website($decision->brand ?? Brand::query()->findOrFail($decision->brand_id));
        $siteRoute = fn (array $params): ?string => $site === null ? null : route('operator.website', ['assetId' => $site->id] + array_filter($params, fn ($v): bool => $v !== null && $v !== ''));
        $url = match ($decision->action_type) {
            'open_content_studio_idea' => $siteRoute(['tab' => 'studio', 'studio_cluster' => $id]),
            'open_fix' => match ($prefix) {
                'u' => $siteRoute(['tab' => 'scorecard', 'url_q' => $fact['path'] ?? null]),
                'std' => $siteRoute(['tab' => 'standards']),
                default => $siteRoute(['tab' => 'seo']),
            },
            'merge_redirect' => $siteRoute(['tab' => 'scorecard', 'karar' => 'merge', 'url_q' => $prefix === 'u' ? ($fact['path'] ?? null) : null]),
            'set_service_area', 'map_service' => route('operator.brand.edit', ['brandId' => $decision->brand_id]),
            default => null,
        };

        return ['label' => $spec['label'], 'kind' => $spec['kind'], 'url' => $spec['kind'] === 'link' ? $url : null];
    }

    public function perform(AnalystDecision $decision, User $user): string
    {
        $target = (string) ($decision->action_params['target'] ?? '');
        if (! str_starts_with($target, 'tc:')) {
            throw ValidationException::withMessages(['analyst' => 'Bu kart için çalıştırılacak bir işlem yok.']);
        }
        $site = $this->facts->website(Brand::query()->findOrFail($decision->brand_id));
        $cluster = $site === null ? null : TopicCluster::query()->where('digital_asset_id', $site->id)->find((int) substr($target, 3));
        if ($cluster === null) {
            throw ValidationException::withMessages(['analyst' => 'Konu artık konu haritasında yok; yeniden analiz edin.']);
        }

        return match ($decision->action_type) {
            'prepare_article' => $this->prepareArticle($site, $cluster, $user),
            'prepare_page_update' => $this->preparePageUpdate($cluster),
            default => throw ValidationException::withMessages(['analyst' => 'Bu kart için çalıştırılacak bir işlem yok.']),
        };
    }

    public function baseline(AnalystDecision $decision): array
    {
        $brand = Brand::query()->find($decision->brand_id);
        $site = $brand !== null ? $this->facts->website($brand) : null;

        return $site === null ? [] : ['organic_clicks_28d' => $this->facts->organicClicks($site)['current']];
    }

    public static function urlRef(string $urlHash): string
    {
        return 'u:'.substr($urlHash, 0, 10);
    }

    private function prepareArticle(DigitalAsset $site, TopicCluster $cluster, User $user): string
    {
        $idea = app(ContentIdeaPlanner::class)->ideaForCluster($cluster)['idea'];
        if ($idea === null) {
            throw ValidationException::withMessages(['analyst' => 'Bu konu sitede zaten yazılı; yeni yazı hazırlanmadı.']);
        }
        app(ContentStudio::class)->queueWrite($site, [(int) $idea->id], [], $user);

        return 'Yazı hazırlanıyor: '.$idea->title.'. Hazır olunca İçerik Stüdyosu’nda.';
    }

    private function preparePageUpdate(TopicCluster $cluster): string
    {
        app(ContentStudio::class)->prepareUpdate($cluster);

        return 'Güncelleme taslağı hazırlanıyor; site ekranının Düzeltmeler sekmesinde incele.';
    }
}
