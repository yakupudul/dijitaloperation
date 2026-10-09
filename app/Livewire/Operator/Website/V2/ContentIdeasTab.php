<?php

namespace App\Livewire\Operator\Website\V2;

use App\Jobs\Site\GenerateContentIdeasJob;
use App\Models\BrandClusterPage;
use App\Models\BrandContentIdea;
use App\Models\Cluster;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Queries\ClusterEditor;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\ContentIdeaPool;
use App\Services\Site\ContentIdeaState;
use App\Services\Site\ContentIdeaSubject;
use App\Services\Site\PageTechnical;
use App\Services\Site\SiteFlow;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Web sitesi › Sorgular › İçerik fikirleri (docs/product/CONTENT_IDEAS_BLUEPRINT.md §5): every approved cluster of the
 * brand's services is a main idea row (its page, nightly score, state and reason), the pool's extra ideas of the
 * cluster below it. Top: "Eşleştir" (AI reads the stored pages for every idea). Row: "Yeniden keşfet", "SEO analizi"
 * (recipe), "AI ile geliştir" (state Geliştirilmeli, WordPress page → new version under Öneriler → Güncelle),
 * "AI ile üret" (no page after Yeniden keşfet → article → İçerik → WordPress draft), "Düzenle" (page lock, extra
 * URLs, target query, excluded — same ClusterEditor as Sorgular) and per cluster "Yeni fikir üret" with brand
 * context. Every AI step runs on the queue.
 */
class ContentIdeasTab extends Component
{
    use WithPagination;

    private const int PER_PAGE = 100;

    private const int PAGE_OPTIONS = 50;

    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'hizmet')]
    public string $service = '';

    #[Url(as: 'tur')]
    public string $type = '';

    #[Url(as: 'durum')]
    public string $state = '';

    /** Open edit / recipe panel: "main-12", "extra-4". */
    public string $open = '';

    /** @var array<int, array{page?: string, state?: string, extra?: list<string>, target?: string, excluded?: bool}> */
    public array $edit = [];

    /** @var array<int, string> extra idea usage id → page id */
    public array $ideaPage = [];

    public string $pageSearch = '';

    public string $ideaCount = '3';

    public string $message = '';

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['service', 'type', 'state'], true)) {
            $this->resetPage();
        }
    }

    /** "Eşleştir": rule rows, AI match + gaps of every main and extra idea (ClusterAudit). */
    public function matchAll(): void
    {
        if (SiteFlow::auditRunning($this->assetId)) {
            $this->message = 'Eşleştirme zaten çalışıyor · bitince sonuçlar burada.';

            return;
        }
        SiteOperations::dispatch($this->assetId, SiteOperations::CLUSTER_AUDIT);
        $this->message = 'Eşleştirme kuyruğa alındı · her fikir için sayfalar okunur, durum ve eksikler yazılır.';
    }

    /** "Onayla" on a pending cluster of the brand's services: approved (shared library), its row lands here (rules, no AI). */
    public function approveCluster(int $clusterId, ClusterEditor $editor, ClusterPageMapper $mapper): void
    {
        $this->approvePending([$clusterId], $editor, $mapper);
    }

    /** "Tümünü onayla": every pending cluster of the brand's services. */
    public function approveAllClusters(ClusterEditor $editor, ClusterPageMapper $mapper): void
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        abort_if($brand === null, 404);
        $this->approvePending(SiteScope::pendingClusters($brand)->pluck('id')->map(fn ($id): int => (int) $id)->all(), $editor, $mapper);
    }

    /** "301 ile birleştir" on an overlapping page of a cluster (approved WordPress write, undoable). */
    public function mergeOverlap(int $suggestionId, ClusterOverlaps $overlaps): void
    {
        $overlaps->redirect($this->overlap($suggestionId), auth()->user());
        $this->message = '301 siteye gönderildi: yönlendirme MoxDOP eklentisine yazılır (WP › Araçlar › MoxDOP yönlendirmeleri), sayfa taslağa alınır · İş listesi › geçmişten geri alınabilir.';
    }

    /** "Ayrı kalsın": both pages stay. */
    public function keepOverlap(int $suggestionId, ClusterOverlaps $overlaps): void
    {
        $overlaps->keep($this->overlap($suggestionId), auth()->user());
        $this->message = 'İki sayfa ayrı kalıyor; çakışma değişmedikçe yeniden önerilmez.';
    }

    /** "Akışı ilerlet": the next due step of the site flow now (the flow starts AI work only on this click). */
    public function advanceFlow(): void
    {
        $result = SiteFlow::advance(DigitalAsset::query()->findOrFail($this->assetId), manual: true);
        $this->message = match ($result) {
            'setup' => 'Sayfa sınıflandırma ve hizmet ↔ sayfa başladı; bitince küme ↔ sayfa kendiliğinden gelir.',
            'audit' => 'Küme ↔ sayfa eşleştirme başladı.',
            'running' => 'Akış zaten çalışıyor.',
            'paused' => 'Son eşleştirme hata verdi; kendiliğinden '.SiteFlow::FAILURE_PAUSE_HOURS.' saat sonra yeniden denenir. Hemen denemek için «Eşleştir».',
            'waiting:wordpress' => 'WordPress eklentisi bağlı değil: akış eklenti eşleşince çalışır.',
            'waiting:pages' => 'Sitenin sayfaları henüz toplanmadı.',
            'waiting:not_operational' => 'Pasif müşteri: AI çalışmaz.',
            default => 'Akış güncel: değişen bir şey yok.',
        };
    }

    public function rediscover(string $kind, int $id): void
    {
        [$kind, $id] = $this->subjectKey($kind, $id);
        SiteOperations::dispatch($this->assetId, SiteOperations::REDISCOVER, ['kind' => $kind, 'id' => $id]);
        $this->message = 'Yeniden keşfediliyor · yalnız bu satır, sisteme çekilmiş sayfalarla.';
    }

    public function recipe(string $kind, int $id): void
    {
        [$kind, $id] = $this->subjectKey($kind, $id);
        SiteOperations::dispatch($this->assetId, SiteOperations::RECIPE, ['kind' => $kind, 'id' => $id]);
        $this->open = $kind.'-'.$id;
        $this->message = 'SEO analizi hazırlanıyor.';
    }

    /** "AI ile geliştir": only for Geliştirilmeli rows whose page comes from WordPress. */
    public function improve(string $kind, int $id): void
    {
        $subject = $this->subject($kind, $id);
        $row = $this->row($subject);
        if ($row['state'] !== 'improve' || $subject->page()?->wp_post_id === null) {
            $this->message = 'AI ile geliştir yalnız "Geliştirilmeli" durumundaki ve WordPress’ten gelen sayfada çalışır.';

            return;
        }
        SiteOperations::dispatch($this->assetId, SiteOperations::FIX_GAPS, $subject->params());
        $this->message = 'Sayfanın yeni sürümü hazırlanıyor; hazır olunca "Önizle ve güncelle" ile eski ↔ yeni karşılaştırıp onaylarsın.';
    }

    /** "AI ile üret": only for Sayfa yok rows that "Yeniden keşfet" also found no page for. */
    public function produce(string $kind, int $id): void
    {
        $subject = $this->subject($kind, $id);
        $row = $this->row($subject);
        if ($row['state'] !== 'no_page' || ($subject->row->rediscovered_at === null && $subject->row->audited_at === null)) {
            $this->message = 'Önce "Eşleştir" ya da "Yeniden keşfet": sitede uygun sayfa yoksa AI ile üretilir.';

            return;
        }
        SiteOperations::dispatch($this->assetId, SiteOperations::PRODUCE, $subject->params());
        $this->message = 'Yeni sayfa yazılıyor; hazır olunca İçerik sekmesinde inceleyip WordPress taslağı olarak gönderirsin.';
    }

    /** "Yeni fikir üret" from the brand screen: brand context and site pages go along. */
    public function generateIdeas(int $clusterId): void
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        abort_if($brand === null || ! BrandClusterPage::query()->where('website_asset_id', $site->id)->where('cluster_id', $clusterId)->exists(), 404);
        if ((Cache::get(ContentIdeaPool::cacheKey($clusterId))['status'] ?? null) === 'running') {
            return;
        }
        $count = max(1, min(ContentIdeaPool::MAX_COUNT, (int) $this->ideaCount));
        Cache::put(ContentIdeaPool::cacheKey($clusterId), ['status' => 'running', 'added' => 0, 'rejected' => []], now()->addHour());
        GenerateContentIdeasJob::dispatch($clusterId, (int) $brand->id, $count, auth()->id());
        $this->message = $count.' yeni içerik fikri üretiliyor · havuza düşenler bu kümenin altında görünür; "Yeniden keşfet" ile sayfası aranır.';
    }

    public function toggle(string $key): void
    {
        $this->open = $this->open === $key ? '' : $key;
    }

    /** Main idea: brand-only edit through ClusterEditor (page / state lock, extra URLs, target query, excluded). */
    public function saveMain(int $rowId, ClusterEditor $editor): void
    {
        $row = BrandClusterPage::query()->where('website_asset_id', $this->assetId)->findOrFail($rowId);
        $edit = $this->edit[$rowId] ?? [];
        $page = (string) ($edit['page'] ?? ($row->page_id ?? ''));
        $values = ['target_query_override' => (string) ($edit['target'] ?? $row->target_query_override), 'excluded' => (bool) ($edit['excluded'] ?? $row->excluded)];
        $pageId = ctype_digit($page) ? (int) $page : null;
        if ($pageId !== $row->page_id) {
            $values += ['page_id' => $pageId, 'state' => $pageId === null ? 'no_page' : (string) $row->state];
        }
        if (array_key_exists('extra', $edit)) {
            $values['extra_page_ids'] = array_map('intval', array_filter((array) $edit['extra'], fn ($id): bool => ctype_digit((string) $id)));
        }
        $editor->brandRow($row, $values);
        unset($this->edit[$rowId]);
        $this->open = '';
        $this->message = 'Kaydedildi (bu markaya özel; elle seçilen sayfa eşlemede korunur).';
    }

    /** Extra idea: the operator's page choice wins (locked); "— sayfa yok" unlocks. */
    public function saveIdea(int $usageId): void
    {
        $usage = BrandContentIdea::query()->where('website_asset_id', $this->assetId)->findOrFail($usageId);
        $page = (string) ($this->ideaPage[$usageId] ?? '');
        $pageId = ctype_digit($page) && Page::query()->where('website_asset_id', $this->assetId)->whereKey((int) $page)->exists() ? (int) $page : null;
        $usage->forceFill(['page_id' => $pageId, 'locked' => $pageId !== null, 'coverage' => $pageId === null ? 'none' : null, 'gaps' => null,
            'state' => $pageId === null ? 'no_page' : null, 'reason' => $pageId !== null ? 'Sayfa elle seçildi.' : null])->save();
        $this->open = '';
        $this->message = $pageId !== null ? 'Sayfa elle seçildi · "Yeniden keşfet" ile kapsamı okunur.' : 'Seçim kaldırıldı.';
    }

    public function render(): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        [$mains, $groups] = $this->clusterGroups($site);

        $filtered = $groups->filter(function (array $g): bool {
            if ($this->service !== '' && (string) $g['cluster']->service_id !== $this->service) {
                return false;
            }
            $rows = $g['mains']->map(fn (array $r): array => $r + ['type' => (string) $g['cluster']->page_type])
                ->merge($g['extras']->map(fn (array $r): array => $r + ['type' => (string) $r['idea']->type]));

            return $rows->contains(fn (array $r): bool => ($this->type === '' || $r['type'] === $this->type) && ($this->state === '' || $r['state'] === $this->state));
        })->sortBy([fn (array $a, array $b): int => [$a['order'], $b['demand']] <=> [$b['order'], $a['demand']]])->values();
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator($filtered->forPage($page, self::PER_PAGE)->values(), $filtered->count(), self::PER_PAGE, $page, ['path' => request()->url()]);

        foreach ($paginator->getCollection() as $g) {
            foreach ($g['mains'] as $r) {
                $row = $r['model'];
                $this->edit[$row->id] ??= ['page' => (string) ($row->page_id ?? ''), 'extra' => array_map('strval', (array) $row->extra_page_ids),
                    'target' => (string) ($row->target_query_override ?? ''), 'excluded' => (bool) $row->excluded];
            }
            foreach ($g['extras'] as $r) {
                if ($r['model'] !== null) {
                    $this->ideaPage[$r['model']->id] ??= (string) ($r['model']->page_id ?? '');
                }
            }
        }
        $statuses = $this->statuses($paginator->getCollection());

        return view('livewire.operator.website.v2.content-ideas-tab', [
            'brand' => $brand,
            'pendingClusters' => $brand !== null ? SiteScope::pendingClusters($brand) : collect(),
            'overlaps' => Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('decision_key', ClusterOverlaps::DECISION)
                ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::DISMISSED, Suggestion::APPLIED])->orderBy('id')->get()
                ->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id)
                ->groupBy(fn (Suggestion $s): int => (int) data_get($s->action, 'row_id'))->all(),
            'flow' => SiteFlow::steps($site),
            'groups' => $paginator,
            'services' => $mains->mapWithKeys(fn (BrandClusterPage $row): array => [(string) $row->cluster->service_id => (string) ($row->cluster->service?->primaryName?->raw_label ?? '—')])->sort()->all(),
            'counts' => $groups->flatMap(fn (array $g): Collection => $g['mains']->merge($g['extras']))->countBy('state')->all(),
            'pageOptions' => $this->pageOptions($site, $paginator->getCollection()),
            'statuses' => $statuses,
            'auditStatus' => SiteOperations::line(SiteOperations::status($site->id, SiteOperations::CLUSTER_AUDIT)),
            'ideaStatuses' => collect($paginator->getCollection())->mapWithKeys(fn (array $g): array => [(int) $g['cluster']->id => Cache::get(ContentIdeaPool::cacheKey((int) $g['cluster']->id))])->filter()->all(),
            'suggestions' => $this->suggestions($paginator->getCollection()),
            'polling' => in_array('çalışıyor…', $statuses, true) || SiteOperations::line(SiteOperations::status($site->id, SiteOperations::CLUSTER_AUDIT)) === 'çalışıyor…'
                || collect($paginator->getCollection())->contains(fn (array $g): bool => (Cache::get(ContentIdeaPool::cacheKey((int) $g['cluster']->id))['status'] ?? null) === 'running'),
        ]);
    }

    /**
     * The brand rows of the site and one group per cluster: main rows (state, score) and the pool's extra ideas.
     *
     * @return array{0: Collection<int, BrandClusterPage>, 1: Collection<int, array{cluster: Cluster, demand: int, mains: Collection<int, array<string, mixed>>, extras: Collection<int, array<string, mixed>>, order: int}>}
     */
    protected function clusterGroups(DigitalAsset $site): array
    {
        $mains = BrandClusterPage::query()->with(['cluster.service.primaryName', 'cluster.mainQuery', 'page'])->where('website_asset_id', $site->id)
            ->orderBy('id')->get()->filter(fn (BrandClusterPage $row): bool => $row->cluster !== null);
        $clusterIds = $mains->pluck('cluster_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $ideas = ContentIdea::query()->with('originBrand:id,name')->where('status', 'active')->whereIn('cluster_id', $clusterIds ?: [0])->orderBy('id')->get();
        $usages = BrandContentIdea::query()->with('page')->where('website_asset_id', $site->id)->whereIn('content_idea_id', $ideas->pluck('id')->all() ?: [0])->get()->keyBy('content_idea_id');
        $scores = DB::table('cluster_page_scores')->whereIn('brand_cluster_page_id', $mains->pluck('id')->all() ?: [0])->get()->keyBy('brand_cluster_page_id');
        $demand = DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])
            ->where('q.hidden', false)->groupBy('cq.cluster_id')->selectRaw('cq.cluster_id, sum(q.impressions) as total')->pluck('total', 'cq.cluster_id');
        $technical = PageTechnical::many($mains->pluck('page')->merge($usages->pluck('page'))->filter()->unique('id'));

        $groups = $mains->groupBy('cluster_id')->map(function (Collection $rows) use ($ideas, $usages, $scores, $demand, $technical): array {
            $cluster = $rows->first()->cluster;
            $mainRows = $rows->map(function (BrandClusterPage $row) use ($scores, $technical): array {
                $score = $scores[$row->id] ?? null;
                $resolved = $row->excluded ? ['state' => 'excluded', 'reason' => 'Bu markada hariç tutuldu.', 'technical' => null]
                    : ContentIdeaState::resolve($row->page, $row->coverage, $row->gaps, $score, (array) json_decode((string) ($score->page_shares ?? '[]'), true),
                        (string) $row->reason, $row->page !== null ? ($technical[$row->page->id] ?? null) : null);

                return ['kind' => ContentIdeaSubject::MAIN, 'model' => $row, 'score' => $score] + $resolved;
            })->values();
            $extraRows = $ideas->where('cluster_id', $cluster->id)->map(function (ContentIdea $idea) use ($usages, $technical): array {
                $usage = $usages->get($idea->id);
                $resolved = $usage === null || $usage->audited_at === null && $usage->page_id === null
                    ? ['state' => 'unchecked', 'reason' => 'Henüz eşleştirilmedi — "Yeniden keşfet"', 'technical' => null]
                    : ContentIdeaState::resolve($usage->page, $usage->coverage, $usage->gaps, null, [], (string) $usage->reason,
                        $usage->page !== null ? ($technical[$usage->page->id] ?? null) : null);

                return ['kind' => ContentIdeaSubject::EXTRA, 'idea' => $idea, 'model' => $usage, 'score' => null] + $resolved;
            })->sortBy(fn (array $r): int => ContentIdeaState::ORDER[$r['state']] ?? 9)->values();
            $first = $mainRows->sortBy(fn (array $r): int => ContentIdeaState::ORDER[$r['state']] ?? 9)->first();

            return ['cluster' => $cluster, 'demand' => (int) ($demand[$cluster->id] ?? 0), 'mains' => $mainRows, 'extras' => $extraRows,
                'order' => ContentIdeaState::ORDER[$first['state']] ?? 9];
        });

        return [$mains, $groups];
    }

    private function overlap(int $suggestionId): Suggestion
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $suggestion = Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('decision_key', ClusterOverlaps::DECISION)->find($suggestionId);
        abort_if($suggestion === null || (int) data_get($suggestion->action, 'site_id') !== (int) $site->id, 404);

        return $suggestion;
    }

    /** @param  list<int>  $clusterIds */
    private function approvePending(array $clusterIds, ClusterEditor $editor, ClusterPageMapper $mapper): void
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        abort_if($brand === null, 404);
        $pending = SiteScope::pendingClusters($brand)->keyBy('id');
        $approved = 0;
        foreach ($clusterIds as $id) {
            if (($cluster = $pending->get($id)) !== null) {
                // The button itself is the operator's approval of the shared cluster (other brands of the service get it too).
                $editor->approve($cluster, confirmed: true);
                $approved++;
            }
        }
        abort_if($approved === 0, 404);
        $mapper->refresh($site, judge: false);
        $this->message = $approved.' küme onaylandı ve listeye eklendi · sayfa kontrolü için "Eşleştir".';
    }

    /**
     * Operation lines per row ("main-12:recipe" => "çalışıyor…").
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<string, string>
     */
    protected function statuses(Collection $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            foreach ([...$g['mains'], ...$g['extras']] as $r) {
                if ($r['model'] === null) {
                    continue;
                }
                $params = ['kind' => $r['kind'], 'id' => (int) $r['model']->id];
                foreach ([SiteOperations::REDISCOVER, SiteOperations::RECIPE, SiteOperations::FIX_GAPS, SiteOperations::PRODUCE] as $op) {
                    $line = SiteOperations::line(SiteOperations::status($this->assetId, $op, $params));
                    if ($line !== null) {
                        $out[$r['kind'].'-'.$r['model']->id.':'.$op] = $line;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The "AI ile geliştir" (missing topic) and "AI ile üret" (content) suggestions of the rows: row key → suggestion.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<string, Suggestion>
     */
    protected function suggestions(Collection $groups): array
    {
        $brandId = DigitalAsset::query()->whereKey($this->assetId)->value('brand_id');
        $clusterIds = $groups->map(fn (array $g): int => (int) $g['cluster']->id)->all();
        $out = [];
        Suggestion::query()->where('brand_id', $brandId)->whereIn('cluster_id', $clusterIds ?: [0])->whereIn('action_type', ['missing_topic', 'content'])
            ->orderBy('id')->get(['id', 'action_type', 'action', 'status', 'applied_at'])
            ->each(function (Suggestion $s) use (&$out): void {
                $kind = data_get($s->action, 'kind');
                $id = data_get($s->action, 'id') ?? data_get($s->action, 'row_id');
                $kind = in_array($kind, [ContentIdeaSubject::MAIN, ContentIdeaSubject::EXTRA], true) ? $kind : (data_get($s->action, 'row_id') !== null ? ContentIdeaSubject::MAIN : null);
                if ($kind !== null && $id !== null) {
                    $out[$kind.'-'.$id.':'.$s->action_type] = $s;
                }
            });

        return $out;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array<int, string>
     */
    protected function pageOptions(DigitalAsset $site, Collection $groups): array
    {
        $term = trim($this->pageSearch);
        $chosen = $groups->flatMap(fn (array $g): array => [...$g['mains']->flatMap(fn (array $r): array => $r['model']->pageIds())->all(),
            ...$g['extras']->map(fn (array $r): ?int => $r['model']?->page_id)->filter()->all()])->unique()->values()->all();
        $options = Page::query()->where('website_asset_id', $site->id)->whereIn('category', ['hizmet', 'lokasyon', 'blog', 'sss'])
            ->when($term !== '', fn ($q) => $q->where(fn ($s) => $s->where('path', 'like', '%'.$term.'%')->orWhere('title', 'like', '%'.$term.'%')))
            ->orderBy('path')->limit(self::PAGE_OPTIONS)->pluck('path', 'id')->all()
            + ($chosen === [] ? [] : Page::query()->whereIn('id', $chosen)->orderBy('path')->pluck('path', 'id')->all());
        asort($options);

        return $options;
    }

    /** @return array{0: string, 1: int} an extra idea without a usage row gets one (the idea id is passed then) */
    private function subjectKey(string $kind, int $id): array
    {
        if ($kind === 'idea') {
            $site = DigitalAsset::query()->findOrFail($this->assetId);
            $idea = ContentIdea::query()->whereIn('cluster_id', BrandClusterPage::query()->where('website_asset_id', $site->id)->select('cluster_id'))->findOrFail($id);

            return [ContentIdeaSubject::EXTRA, (int) BrandContentIdea::query()->firstOrCreate(['brand_id' => $site->brand_id, 'content_idea_id' => $idea->id, 'website_asset_id' => $site->id])->id];
        }
        abort_unless(ContentIdeaSubject::find($this->assetId, $kind, $id) !== null, 404);

        return [$kind, $id];
    }

    private function subject(string $kind, int $id): ContentIdeaSubject
    {
        $subject = ContentIdeaSubject::find($this->assetId, $kind, $id);
        abort_if($subject === null, 404);

        return $subject;
    }

    /** @return array{state: string, reason: string, technical: ?array<string, mixed>} */
    private function row(ContentIdeaSubject $subject): array
    {
        $row = $subject->row;
        $score = $subject->score();

        return ContentIdeaState::resolve($subject->page(), $row->coverage, $row->gaps, $score,
            (array) json_decode((string) ($score->page_shares ?? '[]'), true), (string) $row->reason);
    }
}
