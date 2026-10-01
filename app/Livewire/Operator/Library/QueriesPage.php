<?php

namespace App\Livewire\Operator\Library;

use App\Enums\OfferingStatus;
use App\Jobs\Queries\ApplyQueryRulesJob;
use App\Jobs\Queries\AssignQueryServicesJob;
use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\ProposeQueryRulesJob;
use App\Jobs\Queries\RescanQueriesJob;
use App\Jobs\Site\GenerateContentIdeasJob;
use App\Livewire\Concerns\PreviewsKeywordImpact;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\Page;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\QueryReview;
use App\Models\QueryReviewItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\KeywordInsights;
use App\Services\Queries\PendingQueries;
use App\Services\Queries\QueryClusterer;
use App\Services\Queries\QueryClusterQueue;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryPlanner;
use App\Services\Queries\QueryRescanner;
use App\Services\Queries\QueryRuleEngine;
use App\Services\Queries\QueryRuleProposer;
use App\Services\Queries\QueryServiceAssigner;
use App\Services\Site\ContentIdeaPool;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sorgular: the one query store (query_sources → queries → brand_queries) with tabs Sorgular · Bekleyenler ·
 * Silinecekler · Kümeler · Filtre sepeti · Eşleme kelimeleri and "AI ile planla". Reads only; the pipeline, AI
 * proposals, clustering and the rescan (filter / matching keyword changes → proposals in the permanent Silinecekler
 * pool the operator approves or keeps) run as queued jobs. Eşleme kelimeleri has sub-views Kelimeler (keyword impact
 * preview while typing) · Kelime önerileri · Çakışmalar · Sektör uyumu (KeywordInsights; keyword-only, no AI).
 *
 * Bulk selection: ticked ids (any page) or "filtreye uyan tümü" (`selectAll`: the current filter as a query, minus the
 * unticked `excluded` ids) — bulk actions run as one query, never an id list of the whole library. On Silinecekler the
 * same selection holds pool line ids.
 */
#[Layout('operator.layouts.app')]
#[Title('Sorgular')]
final class QueriesPage extends Component
{
    use PreviewsKeywordImpact;
    use WithPagination;

    public const array TABS = ['queries' => 'Sorgular', 'pending' => 'Bekleyenler', 'deletions' => 'Silinecekler', 'clusters' => 'Kümeler', 'filters' => 'Filtre sepeti', 'keywords' => 'Eşleme kelimeleri'];

    private const int NEGATIVE_LINES = 30;

    /** Eşleme kelimeleri sub-views. */
    public const array KEYWORD_VIEWS = ['words' => 'Kelimeler', 'suggestions' => 'Kelime önerileri', 'conflicts' => 'Çakışmalar', 'sectors' => 'Sektör uyumu'];

    public const array SOURCE_LABELS = ['gsc' => 'GSC', 'google_ads' => 'Ads', 'gbp' => 'GBP'];

    public const int PER_PAGE = 50;

    /** Lines of the "AI ile hizmet öner" checklist per page. */
    public const int ASSIGN_PER_PAGE = 100;

    #[Url(history: true)]
    public string $tab = 'queries';

    #[Url(history: true)]
    public string $sector = '';

    /** '' all · '__none' unassigned (Atanmamış) · '__any' assigned (Atanmış) · service id */
    #[Url(history: true)]
    public string $service = '';

    /** '' all · '__none' no cluster · cluster id */
    #[Url(history: true)]
    public string $cluster = '';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** "Gizlenenler": the hidden queries (with "Geri al"). */
    #[Url(history: true)]
    public bool $hidden = false;

    /** "Varyantları birleştir": one row per variant group (the head, "+N varyant"); bulk actions reach the whole group. */
    #[Url(history: true)]
    public bool $variants = true;

    /** Filtre sepeti "Toplu ekle": one term per line. */
    public string $bulkTerms = '';

    /** @var list<int|string> */
    public array $selected = [];

    /** "Filtreye uyan tümünü seç": every query of the current filter (across pages) minus `excluded`. */
    public bool $selectAll = false;

    /** @var list<int> unticked rows while `selectAll` is on */
    public array $excluded = [];

    /** "AI ile hizmet öner" checklist: lines are ticked unless flipped; `assignInvert` = start from none ticked. */
    public bool $assignInvert = false;

    /** @var list<int> */
    public array $assignFlip = [];

    /** @var list<int> unticked proposed matching keywords */
    public array $assignKeywordSkip = [];

    public int $assignPage = 0;

    /** Filtre sepeti "AI ile oluştur": the operator's own instruction, sent with the stored prompt. */
    public string $filterInstruction = '';

    /** @var list<int> unticked lines of the filter proposal */
    public array $filterSkip = [];

    /** @var list<int|string> unticked lines of the "Sorgularda tara" result */
    public array $scanSkip = [];

    public string $bulkService = '';

    public string $message = '';

    public bool $rulesOpen = false;

    /** @var array<int|string, bool> */
    public array $pickTerms = [];

    /** @var array<int|string, bool> */
    public array $pickKeywords = [];

    #[Locked]
    public ?int $openClusterId = null;

    /** @var array{name?: string, intent?: string, page_type?: string, user_need?: string, main_query_id?: string, representative_query_ids?: list<int|string>, subtopics?: string, exclusions?: string} */
    public array $clusterForm = [];

    /** "Ortak kütüphaneyi düzenle": shared cluster edits that affect brands. */
    public bool $confirmShared = false;

    public string $addQueryText = '';

    /** "Yeni fikir üret": how many ideas (1–5). */
    public string $ideaCount = '3';

    public string $brandId = '';

    public string $brandTarget = '';

    public string $brandPage = '';

    public bool $brandExcluded = false;

    /** @var list<int|string> */
    public array $selectedClusterQueries = [];

    public string $moveTarget = '';

    public string $splitName = '';

    /** @var list<int|string> */
    public array $mergeIds = [];

    public string $termText = '';

    public string $termSector = '';

    /** @var array<int|string, string> */
    public array $newKeyword = [];

    /** "Filtreye ekle" popup: proposed terms (one per line), their sector and the selected queries. */
    public bool $negOpen = false;

    public string $negText = '';

    public string $negSector = '';

    /** @var list<int> */
    #[Locked]
    public array $negIds = [];

    public bool $negAwaiting = false;

    /** @var list<int> Bekleyenler lines flipped against the default selection (temiz = selected) */
    public array $pendingFlip = [];

    /** Silinecekler: 'delete' (silinecek sorgular) · 'service' (hizmet değişikliği) */
    #[Url(history: true)]
    public string $reviewKind = QueryReviewItem::DELETE;

    /** Silinecekler: only the lines of one filter term ('' = all) */
    #[Url(history: true)]
    public string $reviewTerm = '';

    /** Silinecekler: "Tutulanlar" (kept lines, with "Geri al") */
    public bool $reviewKept = false;

    /** Eşleme kelimeleri: words · suggestions · conflicts · sectors */
    #[Url(history: true)]
    public string $keywordView = 'words';

    /** @var array<string, string> Kelime önerileri: service picked per n-gram (spaces as "_") */
    public array $suggestPick = [];

    public int $suggestPage = 0;

    /** @var array<int|string, string> Çakışmalar: service picked per query */
    public array $conflictPick = [];

    /** "Kelime ekle" panel (Kelime önerileri "Ekle", Çakışmalar "Daha uzun kelime"): keyword + service, live impact. */
    public bool $draftOpen = false;

    public string $draftKeyword = '';

    public string $draftService = '';

    public function mount(PendingQueries $pending): void
    {
        $this->message = (string) session('queries-message', '');
        $this->reviewKind = in_array($this->reviewKind, [QueryReviewItem::DELETE, QueryReviewItem::SERVICE], true) ? $this->reviewKind : QueryReviewItem::DELETE;
        // Bekleyenler must never list a text a current filter term catches or that is already in the library.
        $pending->pruneIfChanged();
    }

    /** The Silinecekler tab (notifications link here). */
    public static function deletionsUrl(): string
    {
        return route('operator.library.queries', ['tab' => 'deletions'], false);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['sector', 'service', 'cluster', 'search', 'tab', 'hidden', 'variants', 'reviewKind', 'reviewTerm', 'reviewKept', 'keywordView'], true)) {
            $this->resetPage();
            $this->clearSelection();
            $this->pendingFlip = [];
        }
        if ($property === 'sector') {
            $this->service = '';
            $this->cluster = '';
            $this->suggestPage = 0;
            $this->closeDraft();
        }
        if ($property === 'draftKeyword' || $property === 'draftService') {
            $this->previewDraft();
        }
        if ($property === 'reviewKind') {
            $this->reviewTerm = '';
        }
        if ($property === 'service') {
            $this->cluster = '';
            $this->openClusterId = null;
        }
        if ($property === 'brandId' && $this->openClusterId !== null) {
            $row = ctype_digit($this->brandId) ? BrandClusterPage::query()->where('brand_id', (int) $this->brandId)->where('cluster_id', $this->openClusterId)->orderBy('id')->first() : null;
            $this->brandTarget = (string) ($row?->target_query_override ?? '');
            $this->brandPage = (string) ($row?->page_id ?? '');
            $this->brandExcluded = (bool) ($row?->excluded ?? false);
        }
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
            $this->resetPage();
            $this->clearSelection();
        }
    }

    // ── Sorgular ─────────────────────────────────────────────────────────────

    public function selectPage(): void
    {
        $ids = ($this->tab === 'deletions' ? $this->orderedReviewQuery()
            : ($this->tab === 'keywords' ? $this->conflictQuery() : $this->listQuery())->orderByDesc('impressions')->orderBy('id'))
            ->forPage($this->getPage(), self::PER_PAGE)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($this->selectAll) {
            $this->excluded = array_values(array_diff(array_map('intval', $this->excluded), $ids));

            return;
        }
        $this->selected = array_values(array_unique([...$this->selectedIds(), ...$ids]));
    }

    public function selectAllMatching(): void
    {
        $this->selectAll = true;
        $this->excluded = [];
        $this->selected = [];
    }

    public function clearSelection(): void
    {
        $this->selectAll = false;
        $this->excluded = [];
        $this->selected = [];
    }

    /** Row checkbox while every matching query is selected. */
    public function toggleExcluded(int $id): void
    {
        $excluded = array_map('intval', $this->excluded);
        $this->excluded = in_array($id, $excluded, true) ? array_values(array_diff($excluded, [$id])) : [...$excluded, $id];
    }

    public function assignSelected(): void
    {
        $this->actor();
        $service = ctype_digit($this->bulkService) ? ServiceCatalogItem::query()->find((int) $this->bulkService) : null;
        if ($service === null || ! $this->hasSelection()) {
            $this->message = 'Sorgu ve hizmet seçin.';

            return;
        }
        $count = $this->assignQueries($this->targetQuery(), $service);
        $this->message = $count.' sorgu hizmete atandı (kilitli).';
        $this->clearSelection();
    }

    /** "Hizmeti kaldır": no service (locked, so a rescan does not reassign it) and out of its service's clusters. */
    public function unassignSelected(): void
    {
        $this->actor();
        if (! $this->hasSelection()) {
            return;
        }
        $count = DB::transaction(function (): int {
            ClusterQuery::query()->whereIn('query_id', $this->targetQuery()->select('id'))->delete();

            return $this->targetQuery()->update(['service_id' => null, 'assignment' => 'manual', 'locked' => true, 'updated_at' => now()]);
        });
        $this->message = $count.' sorgunun hizmeti kaldırıldı (kilitli).';
        $this->clearSelection();
    }

    public function hideSelected(): void
    {
        $this->actor();
        if (! $this->hasSelection()) {
            return;
        }
        $count = $this->targetQuery()->update(['hidden' => true, 'updated_at' => now()]);
        $this->message = $count.' sorgu gizlendi.';
        $this->clearSelection();
    }

    public function unhideSelected(): void
    {
        $this->actor();
        if (! $this->hasSelection()) {
            return;
        }
        $count = $this->targetQuery()->update(['hidden' => false, 'updated_at' => now()]);
        $this->message = $count.' sorgu geri alındı.';
        $this->clearSelection();
    }

    // ── AI ile hizmet öner ───────────────────────────────────────────────────

    /** Queues the unassigned queries (the chosen sector, else all) for AI in batches; the checklist waits for approval. */
    public function suggestServices(): void
    {
        $actor = $this->actor();
        $sectorId = ctype_digit($this->sector) ? (int) $this->sector : null;
        if (! QueryServiceAssigner::queue($sectorId)->exists()) {
            $this->message = 'Hizmeti atanmamış (sektörü olan) sorgu yok.';

            return;
        }
        QueryServiceAssigner::markRunning((int) $actor->id, $sectorId);
        $this->reset(['assignInvert', 'assignFlip', 'assignKeywordSkip', 'assignPage']);
        AssignQueryServicesJob::dispatch((int) $actor->id, $sectorId);
    }

    public function toggleAssign(int $index): void
    {
        $flip = array_map('intval', $this->assignFlip);
        $this->assignFlip = in_array($index, $flip, true) ? array_values(array_diff($flip, [$index])) : [...$flip, $index];
    }

    public function setAssignAll(bool $ticked): void
    {
        $this->assignInvert = ! $ticked;
        $this->assignFlip = [];
    }

    public function toggleAssignKeyword(int $index): void
    {
        $skip = array_map('intval', $this->assignKeywordSkip);
        $this->assignKeywordSkip = in_array($index, $skip, true) ? array_values(array_diff($skip, [$index])) : [...$skip, $index];
    }

    public function assignPageTo(int $page): void
    {
        $this->assignPage = max(0, $page);
    }

    public function approveAssignments(QueryServiceAssigner $assigner): void
    {
        $actor = $this->actor();
        $proposal = QueryServiceAssigner::current((int) $actor->id);
        if (($proposal['status'] ?? null) !== 'ready') {
            return;
        }
        $indexes = array_keys((array) $proposal['items']);
        $flip = array_map('intval', $this->assignFlip);
        $skip = $this->assignInvert ? array_values(array_diff($indexes, $flip)) : $flip;
        $keywords = array_values(array_diff(array_keys((array) $proposal['keywords']), array_map('intval', $this->assignKeywordSkip)));
        $saved = $assigner->approve($proposal, $skip, $keywords, $actor);
        QueryServiceAssigner::discard((int) $actor->id);
        $this->reset(['assignInvert', 'assignFlip', 'assignKeywordSkip', 'assignPage']);
        $this->message = sprintf('%d sorguya hizmet atandı', $saved['assigned'])
            .($saved['keywords'] > 0 ? sprintf(' · %d eşleme kelimesi eklendi, tarama başladı.', $saved['keywords']) : '.');
    }

    public function closeAssignments(): void
    {
        QueryServiceAssigner::discard((int) $this->actor()->id);
        $this->reset(['assignInvert', 'assignFlip', 'assignKeywordSkip', 'assignPage']);
    }

    public function proposeRules(): void
    {
        $actor = $this->actor();
        $ids = $this->targetIds(QueryRuleProposer::MAX_QUERIES);
        if ($ids === []) {
            $this->message = 'Önce sorgu seçin.';

            return;
        }
        QueryRuleProposer::markRunning((int) $actor->id);
        ProposeQueryRulesJob::dispatch((int) $actor->id, $ids);
        $this->pickTerms = [];
        $this->pickKeywords = [];
        $this->rulesOpen = true;
    }

    public function approveRules(QueryRuleProposer $proposer): void
    {
        $actor = $this->actor();
        $proposal = QueryRuleProposer::current((int) $actor->id);
        if (($proposal['status'] ?? null) !== 'ready') {
            return;
        }
        $terms = array_map('intval', array_keys(array_filter($this->pickTerms)));
        $keywords = array_map('intval', array_keys(array_filter($this->pickKeywords)));
        if ($terms === [] && $keywords === []) {
            $this->message = 'Hiçbir öneri seçilmedi.';

            return;
        }
        $saved = $proposer->approve($proposal, $terms, $keywords, $actor);
        QueryRuleProposer::discard((int) $actor->id);
        if ($saved['terms'] > 0) {
            app(PendingQueries::class)->prune();
        }
        $this->rulesOpen = false;
        $this->message = $saved['terms'] + $saved['keywords'] > 0
            ? sprintf('%d filtre terimi · %d eşleme kelimesi kaydedildi · tarama başladı, hazır olunca bildirim gelir.', $saved['terms'], $saved['keywords'])
            : 'Seçilen öneriler zaten kayıtlı · tarama yok.';
    }

    public function closeRules(): void
    {
        QueryRuleProposer::discard((int) $this->actor()->id);
        $this->rulesOpen = false;
    }

    public function clusterService(): void
    {
        $this->actor();
        if (! ctype_digit($this->service) || ! ServiceCatalogItem::query()->whereKey((int) $this->service)->exists()) {
            $this->message = 'Önce hizmet seçin.';

            return;
        }
        QueryClusterer::start((int) $this->service);
        ClusterQueriesJob::dispatch((int) $this->service);
        $this->message = 'Kümeleme başladı · konular parça parça işlenir, kilitli kümeler korunur.';
    }

    /**
     * Özet satırındaki "Kümele": a service without clusters gets a full run; one with clusters only places its
     * unclustered queries into them.
     */
    public function clusterOne(int $serviceId): void
    {
        $this->actor();
        if (! ServiceCatalogItem::query()->whereKey($serviceId)->exists() || (QueryClusterer::state($serviceId)['status'] ?? null) === 'running') {
            return;
        }
        $place = Cluster::query()->where('service_id', $serviceId)->exists();
        QueryClusterer::start($serviceId, $place ? 'place' : 'full');
        ClusterQueriesJob::dispatch($serviceId);
        $this->message = $place ? 'Yeni sorgular mevcut kümelere yerleştiriliyor.' : 'Kümeleme başladı · konular parça parça işlenir.';
    }

    /** "Hepsini kümele": services with unclustered queries, one at a time, biggest demand first. */
    public function clusterAll(QueryClusterQueue $queue): void
    {
        $this->actor();
        if ((QueryClusterQueue::state()['status'] ?? null) === 'running') {
            return;
        }
        $count = $queue->start();
        $this->message = $count > 0 ? $count.' hizmet sıraya alındı · her biri sırayla, parça parça kümelenir.' : 'Kümelenecek sorgu yok.';
    }

    public function stopClusterAll(QueryClusterQueue $queue): void
    {
        $this->actor();
        $queue->stop();
        $this->message = 'Toplu kümeleme durduruldu · işlenen hizmet o anki parçayı bitirip durur.';
    }

    // ── Kümeler ──────────────────────────────────────────────────────────────

    public function openCluster(int $id): void
    {
        $cluster = Cluster::query()->findOrFail($id);
        $this->openClusterId = $cluster->id;
        $this->fillClusterForm($cluster);
        $this->reset(['selectedClusterQueries', 'moveTarget', 'splitName', 'mergeIds', 'confirmShared', 'addQueryText', 'brandId', 'brandTarget', 'brandPage', 'brandExcluded']);
        $this->resetValidation();
    }

    /** "Yeni fikir üret" (CONTENT_IDEAS_BLUEPRINT §4.3): from Sorgular, without brand context; runs on the queue. */
    public function generateIdeas(): void
    {
        $user = $this->actor();
        $cluster = $this->openedCluster();
        if ((Cache::get(ContentIdeaPool::cacheKey($cluster->id))['status'] ?? null) === 'running') {
            return;
        }
        $count = max(1, min(ContentIdeaPool::MAX_COUNT, (int) $this->ideaCount));
        Cache::put(ContentIdeaPool::cacheKey($cluster->id), ['status' => 'running', 'added' => 0, 'rejected' => []], now()->addHour());
        GenerateContentIdeasJob::dispatch($cluster->id, null, $count, $user->id);
        $this->message = $count.' yeni içerik fikri üretiliyor.';
    }

    public function archiveIdea(int $id): void
    {
        $this->actor();
        ContentIdea::query()->where('cluster_id', $this->openedCluster()->id)->whereKey($id)->update(['status' => 'archived']);
        $this->message = 'Fikir arşivlendi.';
    }

    public function closeCluster(): void
    {
        $this->openClusterId = null;
    }

    public function saveCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $form = $this->clusterForm;
        $form['representative_query_ids'] = array_values((array) ($form['representative_query_ids'] ?? []));
        if (($form['main_query_id'] ?? '') === '') {
            unset($form['main_query_id']);
        }
        $this->fillClusterForm($editor->update($this->openedCluster(), $form, $this->confirmShared));
        $this->message = 'Küme kaydedildi.';
    }

    public function approveCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->approve($this->openedCluster(), $this->confirmShared);
        $this->message = 'Küme onaylandı.';
    }

    public function addQueryToCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->addQuery($this->openedCluster(), $this->addQueryText, $this->confirmShared);
        $this->addQueryText = '';
        $this->fillClusterForm($this->openedCluster());
        $this->message = 'Sorgu kümeye eklendi.';
    }

    public function removeClusterQueries(ClusterEditor $editor): void
    {
        $this->actor();
        $removed = $editor->removeQueries($this->openedCluster(), $this->clusterQueryIds(), $this->confirmShared);
        $this->selectedClusterQueries = [];
        $this->fillClusterForm($this->openedCluster());
        $this->message = $removed.' sorgu kümeden çıkarıldı.';
    }

    public function moveQueries(ClusterEditor $editor): void
    {
        $this->actor();
        $target = ctype_digit($this->moveTarget) ? Cluster::query()->find((int) $this->moveTarget) : null;
        if ($target === null) {
            throw ValidationException::withMessages(['moveTarget' => 'Hedef küme seçin.']);
        }
        $moved = $editor->move($this->clusterQueryIds(), $target, $this->confirmShared);
        $this->selectedClusterQueries = [];
        $this->fillClusterForm($this->openedCluster());
        $this->message = $moved.' sorgu taşındı.';
    }

    public function splitCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $new = $editor->split($this->openedCluster(), $this->clusterQueryIds(), $this->splitName, $this->confirmShared);
        $this->selectedClusterQueries = [];
        $this->splitName = '';
        $this->fillClusterForm($this->openedCluster());
        $this->message = '"'.$new->name.'" kümesi oluşturuldu.';
    }

    public function mergeClusters(ClusterEditor $editor): void
    {
        $this->actor();
        $merged = $editor->merge($this->openedCluster(), array_map('intval', $this->mergeIds), $this->confirmShared);
        $this->mergeIds = [];
        $this->fillClusterForm($this->openedCluster());
        $this->message = $merged.' küme birleştirildi.';
    }

    public function deleteCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->delete($this->openedCluster(), $this->confirmShared);
        $this->openClusterId = null;
        $this->message = 'Küme silindi.';
    }

    /** "Bu markaya özel düzenle": brand_cluster_pages only; the shared cluster does not change. */
    public function saveBrandCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $cluster = $this->openedCluster();
        $brand = ctype_digit($this->brandId) && $editor->affectedBrands($cluster)->contains('id', (int) $this->brandId)
            ? Brand::query()->find((int) $this->brandId) : null;
        if ($brand === null) {
            throw ValidationException::withMessages(['brandId' => 'Marka seçin.']);
        }
        $editor->brandEdit($cluster, $brand, [
            'target_query_override' => $this->brandTarget,
            'excluded' => $this->brandExcluded,
        ] + (ctype_digit($this->brandPage) ? ['page_id' => (int) $this->brandPage] : []));
        $this->message = 'Bu markaya özel kaydedildi.';
    }

    // ── Filtre sepeti ────────────────────────────────────────────────────────

    public function addTerm(): void
    {
        $actor = $this->actor();
        $this->resetValidation();
        $term = trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($this->termText)) ?? '');
        if (mb_strlen($term) < 2 || mb_strlen($term) > 200) {
            throw ValidationException::withMessages(['termText' => 'Terim 2–200 karakter olmalı.']);
        }
        if (QueryNormalizer::isQuestionTerm($term)) {
            throw ValidationException::withMessages(['termText' => 'Soru / bilgi kelimesi (nedir, nasıl…) içeren sorgular içerik kümeleri için tutulur; filtreye eklenmez.']);
        }
        $sectorId = ctype_digit($this->termSector) && ServiceCategory::query()->whereKey((int) $this->termSector)->exists() ? (int) $this->termSector : null;
        if (FilterTerm::query()->where('term', $term)->where('sector_id', $sectorId)->exists()) {
            throw ValidationException::withMessages(['termText' => 'Bu terim sepette zaten var.']);
        }
        FilterTerm::query()->create(['sector_id' => $sectorId, 'term' => $term, 'source' => 'manual', 'created_by' => $actor->id]);
        app(PendingQueries::class)->prune();
        $this->termText = '';
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = '"'.$term.'" eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    /**
     * "Toplu ekle": one term per line (a file of terms pasted in); invalid, question and duplicate lines are skipped,
     * then one rescan.
     */
    public function addBulkTerms(): void
    {
        $actor = $this->actor();
        $sectorId = ctype_digit($this->termSector) && ServiceCategory::query()->whereKey((int) $this->termSector)->exists() ? (int) $this->termSector : null;
        $lines = collect(preg_split('/\r\n|\r|\n/', $this->bulkTerms) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower(trim($line, " \t\"',;"))) ?? ''))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2 && mb_strlen($term) <= 200)->unique()->values();
        $existing = array_fill_keys(FilterTerm::query()->where('sector_id', $sectorId)->pluck('term')->all(), true);
        $saved = 0;
        $skipped = 0;
        foreach ($lines as $term) {
            if (isset($existing[$term]) || QueryNormalizer::isQuestionTerm($term)) {
                $skipped++;

                continue;
            }
            FilterTerm::query()->create(['sector_id' => $sectorId, 'term' => $term, 'source' => 'manual', 'created_by' => $actor->id]);
            $existing[$term] = true;
            $saved++;
        }
        $this->bulkTerms = '';
        if ($saved > 0) {
            app(PendingQueries::class)->prune();
            RescanQueriesJob::dispatch((int) $actor->id);
        }
        $this->message = $saved.' terim eklendi'.($skipped > 0 ? ' · '.$skipped.' satır atlandı (zaten var / soru kelimesi)' : '').($saved > 0 ? ' · tarama başladı.' : '.');
    }

    /** "Kuralları uygula": recomputes the variant / topic keys with the current rules (queued). */
    public function applyRules(): void
    {
        $this->actor();
        ApplyQueryRulesJob::dispatch();
        $this->message = 'Kurallar uygulanıyor (v'.QueryRuleEngine::version().'); birkaç saniye içinde liste güncellenir.';
    }

    public function deleteTerm(int $id): void
    {
        $this->actor();
        FilterTerm::query()->whereKey($id)->delete();
        $this->message = 'Terim silindi.';
    }

    /**
     * "AI ile oluştur": one call per sector (the chosen one, else every used sector) with the stored prompt plus the
     * operator's instruction; the terms come back as a ticked checklist.
     */
    public function generateFilters(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $sectorIds = ctype_digit($this->sector) ? [(int) $this->sector] : $planner->usedSectorIds();
        if ($sectorIds === []) {
            $this->message = 'Önce markalara sektör atayın.';

            return;
        }
        $this->filterSkip = [];
        QueryPlanner::start((int) $actor->id, 'filters', $sectorIds, $this->filterInstruction);
    }

    /** "Durdur" on the running filter step: forgets it so it can be started again. */
    public function stopFilters(): void
    {
        QueryPlanner::reset((int) $this->actor()->id, 'filters');
        $this->message = 'AI adımı durduruldu; yeniden başlatabilirsiniz.';
    }

    public function toggleFilterLine(int $index): void
    {
        $skip = array_map('intval', $this->filterSkip);
        $this->filterSkip = in_array($index, $skip, true) ? array_values(array_diff($skip, [$index])) : [...$skip, $index];
    }

    public function approveFilterProposal(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $proposal = QueryPlanner::current((int) $actor->id, 'filters');
        if (($proposal['status'] ?? null) !== 'ready') {
            return;
        }
        $indexes = array_values(array_diff(array_keys((array) $proposal['items']), array_map('intval', $this->filterSkip)));
        $saved = $planner->applyFilters($proposal, $indexes, $actor);
        QueryPlanner::discard((int) $actor->id, 'filters');
        $this->filterSkip = [];
        if ($saved > 0) {
            app(PendingQueries::class)->prune();
        }
        if ($saved > 0 && QueryPipeline::importedAt() !== null) {
            RescanQueriesJob::dispatch((int) $actor->id);
            $this->message = $saved.' filtre terimi kaydedildi · tarama başladı, hazır olunca bildirim gelir.';

            return;
        }
        $this->message = $saved.' filtre terimi kaydedildi.';
    }

    public function closeFilterProposal(): void
    {
        QueryPlanner::discard((int) $this->actor()->id, 'filters');
        $this->filterSkip = [];
    }

    /**
     * "Sorgularda tara": the words of the library queries that belong in the basket (place, brand, person, off-topic),
     * one job per sector (the chosen one, else every used sector), shown as a ticked checklist.
     */
    public function scanFilters(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $sectorIds = ctype_digit($this->sector) ? [(int) $this->sector] : $planner->usedSectorIds();
        if ($sectorIds === []) {
            $this->message = 'Önce markalara sektör atayın.';

            return;
        }
        $this->scanSkip = [];
        QueryPlanner::start((int) $actor->id, 'scan', $sectorIds, $this->filterInstruction);
    }

    public function stopScan(): void
    {
        QueryPlanner::reset((int) $this->actor()->id, 'scan');
        $this->message = 'Tarama durduruldu; yeniden başlatabilirsiniz.';
    }

    public function toggleScanLine(int $index): void
    {
        $skip = array_map('intval', $this->scanSkip);
        $this->scanSkip = in_array($index, $skip, true) ? array_values(array_diff($skip, [$index])) : [...$skip, $index];
    }

    /** Ticks or unticks every line of one category (Yer adı / Marka / Kişi adı / Alakasız). */
    public function setScanCategory(string $category, bool $ticked): void
    {
        $scan = QueryPlanner::current((int) $this->actor()->id, 'scan');
        $indexes = array_keys(array_filter((array) ($scan['items'] ?? []), fn ($row): bool => is_array($row) && ($row['category'] ?? null) === $category));
        $skip = array_values(array_diff(array_map('intval', $this->scanSkip), $indexes));
        $this->scanSkip = $ticked ? $skip : [...$skip, ...$indexes];
    }

    public function approveScan(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $scan = QueryPlanner::current((int) $actor->id, 'scan');
        if (($scan['status'] ?? null) !== 'ready') {
            return;
        }
        $indexes = array_values(array_diff(array_keys((array) $scan['items']), array_map('intval', $this->scanSkip)));
        $saved = $planner->applyFilters($scan, $indexes, $actor);
        QueryPlanner::discard((int) $actor->id, 'scan');
        $this->scanSkip = [];
        if ($saved > 0) {
            app(PendingQueries::class)->prune();
        }
        if ($saved > 0 && QueryPipeline::importedAt() !== null) {
            RescanQueriesJob::dispatch((int) $actor->id);
            $this->message = $saved.' kelime filtre sepetine eklendi · tarama başladı; silinecek sorgular Silinecekler sekmesine düşer.';

            return;
        }
        $this->message = $saved > 0 ? $saved.' kelime filtre sepetine eklendi.' : 'Yeni kelime eklenmedi.';
    }

    public function closeScan(): void
    {
        QueryPlanner::discard((int) $this->actor()->id, 'scan');
        $this->scanSkip = [];
    }

    // ── Eşleme kelimeleri ────────────────────────────────────────────────────

    public function addKeyword(int $serviceId, ServiceKeywordService $keywords): void
    {
        $this->actor();
        $this->resetValidation();
        $service = ServiceCatalogItem::query()->findOrFail($serviceId);
        try {
            $keywords->add($service, (string) ($this->newKeyword[$serviceId] ?? ''));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['newKeyword.'.$serviceId => collect($exception->errors())->flatten()->first()]);
        }
        $this->newKeyword[$serviceId] = '';
        $this->impact = [];
        RescanQueriesJob::dispatch((int) auth()->id());
        $this->message = 'Kelime eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    public function setKeywordView(string $view): void
    {
        if (array_key_exists($view, self::KEYWORD_VIEWS)) {
            $this->keywordView = $view;
            $this->resetPage();
            $this->clearSelection();
            $this->closeDraft();
        }
    }

    /** "Kelime ekle" panel: saves the keyword as today (then the rescan proposes the changes in Silinecekler). */
    public function saveDraft(ServiceKeywordService $keywords): void
    {
        $actor = $this->actor();
        $service = ctype_digit($this->draftService) ? ServiceCatalogItem::query()->find((int) $this->draftService) : null;
        if ($service === null) {
            throw ValidationException::withMessages(['draftKeyword' => 'Hizmet seçin.']);
        }
        try {
            $saved = $keywords->add($service, $this->draftKeyword);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['draftKeyword' => collect($exception->errors())->flatten()->first()]);
        }
        $this->closeDraft();
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = '"'.$saved->label.'" eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    public function closeDraft(): void
    {
        $this->draftOpen = false;
        $this->draftKeyword = '';
        $this->draftService = '';
        $this->impact = [];
    }

    // ── Kelime önerileri ─────────────────────────────────────────────────────

    /** "Ekle": the n-gram with the picked service goes to the "Kelime ekle" panel (impact preview, then save). */
    public function openSuggestion(string $ngram): void
    {
        $this->actor();
        $this->resetValidation();
        $sectorId = $this->sectorId();
        $row = collect($sectorId !== null ? app(KeywordInsights::class)->openSuggestions($sectorId) : [])->firstWhere('ngram', $ngram);
        $this->draftKeyword = (string) ($row['label'] ?? $ngram);
        $this->draftService = (string) ($this->suggestPick[str_replace(' ', '_', $ngram)] ?? '');
        $this->draftOpen = true;
        $this->previewDraft();
    }

    /** "Yok say": the n-gram is not suggested again in this sector. */
    public function dismissSuggestion(string $ngram): void
    {
        $actor = $this->actor();
        if ($this->sectorId() !== null) {
            KeywordInsights::dismiss($this->sectorId(), $ngram, (int) $actor->id);
        }
    }

    /** "Yenile": the n-grams are counted again from the current unassigned queries. */
    public function refreshSuggestions(): void
    {
        $this->actor();
        if ($this->sectorId() !== null) {
            KeywordInsights::forgetSuggestions($this->sectorId());
        }
        $this->suggestPage = 0;
    }

    public function suggestPageTo(int $page): void
    {
        $this->suggestPage = max(0, $page);
    }

    // ── Çakışmalar ───────────────────────────────────────────────────────────

    /** One conflict line: the picked service is assigned by hand (locked, like "Hizmete ata"). */
    public function resolveConflict(int $queryId): void
    {
        $this->actor();
        $service = $this->sectorService((string) ($this->conflictPick[$queryId] ?? ''));
        if ($service === null) {
            $this->message = 'Hizmet seçin.';

            return;
        }
        $count = $this->assignQueries($this->conflictQuery()->whereKey($queryId), $service);
        unset($this->conflictPick[$queryId]);
        $this->message = $count.' sorgu hizmete atandı (kilitli).';
    }

    /** Ticked conflict lines → the bulk service (manual, locked). */
    public function assignConflicts(): void
    {
        $this->actor();
        $service = $this->sectorService($this->bulkService);
        if ($service === null || $this->selectedIds() === []) {
            $this->message = 'Sorgu ve hizmet seçin.';

            return;
        }
        $count = $this->assignQueries($this->conflictQuery()->whereIn('id', $this->selectedIds()), $service);
        $this->clearSelection();
        $this->message = $count.' sorgu hizmete atandı (kilitli).';
    }

    /** "Daha uzun kelime": the query text goes to the "Kelime ekle" panel to be shortened / completed. */
    public function openConflictKeyword(int $queryId): void
    {
        $this->actor();
        $this->resetValidation();
        $this->draftKeyword = (string) Query::query()->whereKey($queryId)->value('text');
        $this->draftService = (string) ($this->conflictPick[$queryId] ?? '');
        $this->draftOpen = true;
        $this->previewDraft();
    }

    // ── Sektör uyumu ─────────────────────────────────────────────────────────

    public function moveMismatch(int $sectorId, string $code): void
    {
        $this->actor();
        $count = KeywordInsights::moveToServiceSector($sectorId, $code);
        $this->message = $count.' sorgu hizmetin sektörüne taşındı.';
    }

    public function clearMismatch(int $sectorId, string $code): void
    {
        $this->actor();
        $count = KeywordInsights::clearMismatchedService($sectorId, $code);
        $this->message = $count.' sorgunun hizmeti kaldırıldı · sektörün eşleme kelimeleri sonraki taramada yeniden atayabilir.';
    }

    private function previewDraft(): void
    {
        if (! $this->draftOpen || ! ctype_digit($this->draftService) || trim($this->draftKeyword) === '') {
            $this->impact = [];

            return;
        }
        $this->previewKeyword((int) $this->draftService, $this->draftKeyword, 'draft');
    }

    /** @return Builder<Query> conflict lines of the chosen sector the keyword rules may still assign */
    private function conflictQuery(): Builder
    {
        $sectorId = $this->sectorId();

        return $sectorId === null ? Query::query()->whereRaw('1 = 0')
            : KeywordInsights::ruleQueries(array_keys(app(KeywordInsights::class)->conflicts($sectorId)));
    }

    /** A service of the chosen sector. */
    private function sectorService(string $id): ?ServiceCatalogItem
    {
        $code = $this->sectorId() !== null ? ServiceCategory::query()->whereKey($this->sectorId())->value('code') : null;

        return ctype_digit($id) && $code !== null ? ServiceCatalogItem::query()->where('sector', $code)->find((int) $id) : null;
    }

    private function sectorId(): ?int
    {
        return ctype_digit($this->sector) ? (int) $this->sector : null;
    }

    /**
     * Manual, locked assignment; the queries leave clusters of other services.
     *
     * @param  Builder<Query>  $target
     */
    private function assignQueries(Builder $target, ServiceCatalogItem $service): int
    {
        return DB::transaction(function () use ($target, $service): int {
            ClusterQuery::query()->whereIn('query_id', (clone $target)->select('id'))
                ->whereIn('cluster_id', Cluster::query()->where('service_id', '!=', $service->id)->select('id'))->delete();

            return (clone $target)->update(['service_id' => $service->id, 'assignment' => 'manual', 'locked' => true, 'updated_at' => now()]);
        });
    }

    public function deleteKeyword(int $id): void
    {
        $actor = $this->actor();
        ServiceMatchingKeyword::query()->whereKey($id)->delete();
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = 'Kelime silindi · tarama başladı, hazır olunca bildirim gelir.';
    }

    // ── Filtreye ekle ────────────────────────────────────────────────────────

    public function openNegatives(): void
    {
        $this->actor();
        $queries = Query::query()->whereIn('id', $this->targetIds(QueryRuleProposer::MAX_QUERIES) ?: [0])->orderByDesc('impressions')->orderBy('id')->get(['id', 'text', 'sector_id']);
        if ($queries->isEmpty()) {
            $this->message = 'Önce sorgu seçin.';

            return;
        }
        $this->negIds = $queries->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->negText = $queries->pluck('text')->implode("\n");
        $this->negSector = (string) ($queries->pluck('sector_id')->filter()->countBy()->sortDesc()->keys()->first() ?? '');
        $this->negAwaiting = false;
        $this->negOpen = true;
    }

    /** "AI ile düzenle": the minimal words that catch the selected queries (one queued call). */
    public function aiNegatives(): void
    {
        $actor = $this->actor();
        QueryRuleProposer::markRunning((int) $actor->id);
        $this->negAwaiting = true;
        ProposeQueryRulesJob::dispatch((int) $actor->id, $this->negIds);
        $this->pollNegatives();
    }

    public function pollNegatives(): void
    {
        $user = auth()->user();
        $proposal = $this->negAwaiting && $user instanceof User ? QueryRuleProposer::current((int) $user->id) : null;
        if ($proposal === null || ($proposal['status'] ?? null) === 'running') {
            return;
        }
        $this->negAwaiting = false;
        QueryRuleProposer::discard((int) $user->id);
        $terms = array_column((array) ($proposal['terms'] ?? []), 'term');
        if (($proposal['status'] ?? null) !== 'ready') {
            $this->message = ['no_provider' => 'AI bağlı değil.'][$proposal['status']] ?? 'AI önerisi alınamadı.';
        } elseif ($terms === []) {
            $this->message = 'AI daha kısa terim önermedi.';
        } else {
            $this->negText = implode("\n", $terms);
        }
    }

    public function saveNegatives(): void
    {
        $actor = $this->actor();
        $sectorId = ctype_digit($this->negSector) && ServiceCategory::query()->whereKey((int) $this->negSector)->exists() ? (int) $this->negSector : null;
        $saved = 0;
        foreach ($this->negativeLines() as $term) {
            if (QueryNormalizer::isQuestionTerm($term)) {
                continue;
            }
            $row = FilterTerm::query()->firstOrCreate(['sector_id' => $sectorId, 'term' => $term], ['source' => 'manual', 'created_by' => $actor->id]);
            $saved += $row->wasRecentlyCreated ? 1 : 0;
        }
        $this->negOpen = false;
        $this->clearSelection();
        if ($saved === 0) {
            $this->message = 'Yeni terim yok.';

            return;
        }
        app(PendingQueries::class)->prune();
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = $saved.' terim filtreye eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    public function closeNegatives(): void
    {
        $this->negOpen = false;
        $this->negAwaiting = false;
    }

    /** @return list<string> */
    private function negativeLines(): array
    {
        $terms = [];
        foreach (preg_split('/\R/u', $this->negText) ?: [] as $line) {
            $term = trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($line)) ?? '');
            if (mb_strlen($term) >= 2 && mb_strlen($term) <= 200) {
                $terms[$term] = true;
            }
        }

        return array_slice(array_keys($terms), 0, self::NEGATIVE_LINES);
    }

    // ── Bekleyenler ──────────────────────────────────────────────────────────

    public function togglePending(int $id): void
    {
        $this->pendingFlip = in_array($id, $this->pendingFlip, true) ? array_values(array_diff($this->pendingFlip, [$id])) : [...$this->pendingFlip, $id];
    }

    public function importPending(PendingQueries $pending): void
    {
        $this->actor();
        $count = $pending->import($this->pendingSelection());
        if ($count > 0) {
            ApplyQueryRulesJob::dispatch();
        }
        $this->pendingFlip = [];
        $this->message = $count.' sorgu içe aktarıldı.';
    }

    public function dismissPending(PendingQueries $pending): void
    {
        $this->actor();
        $count = $pending->dismiss($this->pendingSelection());
        $this->pendingFlip = [];
        $this->message = $count.' sorgu yoksayıldı.';
    }

    // ── Silinecekler ─────────────────────────────────────────────────────────

    /** "Onayla ve sil" (service lines: "Onayla ve uygula"): the selected pool lines are applied. */
    /** "Kütüphaneyi yeniden tara": every library query against the basket, the place rule and the matching keywords. */
    public function rescanLibrary(): void
    {
        RescanQueriesJob::dispatch((int) $this->actor()->id);
        $this->message = 'Tarama başladı; silinecek ve hizmeti değişecek sorgular burada listelenir.';
    }

    public function approveReview(QueryRescanner $rescanner): void
    {
        $this->actor();
        if (! $this->hasSelection() || $this->reviewKept) {
            $this->message = 'Önce satır seçin.';

            return;
        }
        $done = $rescanner->apply($this->reviewTargetIds());
        ApplyQueryRulesJob::dispatch();
        $this->clearSelection();
        $this->message = sprintf('%d sorgu silindi · %d sorgunun hizmeti değişti.', $done['deleted'], $done['changed']);
    }

    /** "Tut": the selected lines leave the list; the same proposal is not offered again (another term / service is). */
    public function keepReview(QueryRescanner $rescanner): void
    {
        $this->actor();
        if (! $this->hasSelection()) {
            $this->message = 'Önce satır seçin.';

            return;
        }
        $count = $rescanner->keep($this->reviewTargetIds(), ! $this->reviewKept);
        $this->clearSelection();
        $this->message = $this->reviewKept ? $count.' satır geri alındı.' : $count.' sorgu tutuldu · aynı öneri tekrar gelmez.';
    }

    /** @return list<int> selected pool line ids (ticked, or every matching line minus the unticked ones) */
    private function reviewTargetIds(): array
    {
        return ($this->selectAll ? $this->reviewQuery()->whereNotIn('id', array_map('intval', $this->excluded) ?: [0])
            : $this->reviewQuery()->whereIn('id', $this->selectedIds() ?: [0]))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return Builder<QueryReviewItem> the Silinecekler list of the current kind / term / sector / search */
    private function reviewQuery(): Builder
    {
        return QueryReviewItem::query()->where('kind', $this->reviewKind)
            ->when($this->reviewKept, fn (Builder $q) => $q->whereNotNull('kept_at'), fn (Builder $q) => $q->whereNull('kept_at'))
            ->when($this->reviewKind === QueryReviewItem::DELETE && $this->reviewTerm !== '', fn (Builder $q) => $q->where('term', $this->reviewTerm))
            ->when(ctype_digit($this->sector) || trim($this->search) !== '', fn (Builder $q) => $q->whereIn('query_id', Query::query()->select('id')
                ->when(ctype_digit($this->sector), fn (Builder $w) => $w->where('sector_id', (int) $this->sector))
                ->when(trim($this->search) !== '', fn (Builder $w) => $w->where('text', 'like', '%'.QueryNormalizer::lower(trim($this->search)).'%'))));
    }

    /** @return Builder<QueryReviewItem> most impressions first */
    private function orderedReviewQuery(): Builder
    {
        return $this->reviewQuery()
            ->orderByDesc(Query::query()->select('impressions')->whereColumn('queries.id', 'query_review_items.query_id'))->orderBy('id');
    }

    /** @return list<int> selected pending ids: every line unless unticked */
    private function pendingSelection(): array
    {
        return $this->pendingQuery()->whereNotIn('id', array_map('intval', $this->pendingFlip) ?: [0])
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Bekleyenler: pending texts that are not in the library and that no filter term catches (the pipeline / rescan
     * prune keeps the table so; the conditions here also hold between two runs).
     *
     * @return Builder<PendingQuery>
     */
    private function pendingQuery(bool $all = false): Builder
    {
        return PendingQuery::query()->where('status', PendingQuery::PENDING)->whereNull('filter_term')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('queries')->whereColumn('queries.text_hash', 'pending_queries.text_hash'))
            ->when(! $all && ctype_digit($this->sector), fn (Builder $q) => $q->where('sector_id', (int) $this->sector));
    }

    public function render(): View
    {
        $user = auth()->user();
        $proposal = $this->rulesOpen && $user instanceof User ? QueryRuleProposer::current((int) $user->id) : null;
        $assign = $this->tab === 'queries' && $user instanceof User ? QueryServiceAssigner::current((int) $user->id) : null;
        $filterProposal = $this->tab === 'filters' && $user instanceof User ? QueryPlanner::current((int) $user->id, 'filters') : null;
        $scan = $this->tab === 'filters' && $user instanceof User ? QueryPlanner::current((int) $user->id, 'scan') : null;
        $queries = $this->tab === 'queries' ? $this->queryList() : null;
        $variantGroups = $queries !== null && $this->variants && ! $this->hidden ? $this->variantGroups($queries->getCollection()) : [];
        $serviceId = ctype_digit($this->service) ? (int) $this->service : null;
        $clusterStatus = $serviceId !== null ? Cache::get(QueryClusterer::cacheKey($serviceId)) : null;
        $overview = $this->tab === 'clusters' && $serviceId === null ? $this->serviceOverview() : null;
        $clusterQueue = $this->tab === 'clusters' ? QueryClusterQueue::state() : null;
        $overviewRunning = $overview !== null && collect($overview)->flatten(1)->contains(fn (array $row): bool => ($row['state']['status'] ?? null) === 'running');

        $openCluster = $this->tab === 'clusters' && $this->openClusterId !== null
            ? Cluster::query()->with(['mainQuery', 'clusterQueries.searchQuery', 'brandPages.brand:id,name', 'brandPages.page:id,url,path'])->find($this->openClusterId) : null;
        $affected = $openCluster !== null ? app(ClusterEditor::class)->affectedBrands($openCluster) : collect();
        $ideaStatus = $openCluster !== null ? Cache::get(ContentIdeaPool::cacheKey((int) $openCluster->id)) : null;
        $pending = $this->tab === 'pending' ? $this->pendingList() : null;
        $reviewCounts = QueryRescanner::openCounts();
        $reviewRunning = QueryReview::query()->where('status', QueryReview::RUNNING)->where('created_at', '>', now()->subHour())->exists();

        return view('livewire.operator.library.queries-page', [
            'sectors' => ServiceCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'services' => $this->serviceOptions(),
            'clusterOptions' => $serviceId !== null ? Cluster::query()->where('service_id', $serviceId)->orderBy('name')->pluck('name', 'id')->all() : [],
            'queries' => $queries,
            'variantGroups' => $variantGroups,
            'ruleStats' => $this->tab === 'queries' ? $this->ruleStats() : null,
            'ruleVersion' => QueryRuleEngine::version(),
            'matchingCount' => $this->selectAll ? ($this->tab === 'deletions'
                ? $this->reviewQuery()->whereNotIn('id', array_map('intval', $this->excluded) ?: [0])->count() : $this->targetQuery()->count()) : null,
            'unassignedCount' => $this->tab === 'queries' ? QueryServiceAssigner::queue(ctype_digit($this->sector) ? (int) $this->sector : null)->count() : 0,
            'assign' => $assign,
            'assignRows' => ($assign['status'] ?? null) === 'ready' ? array_slice((array) $assign['items'], $this->assignPage * self::ASSIGN_PER_PAGE, self::ASSIGN_PER_PAGE, true) : [],
            'filterProposal' => $filterProposal,
            'scan' => $scan,
            'clusters' => $this->tab === 'clusters' && $serviceId !== null ? $this->clusterList($serviceId) : null,
            'unclustered' => $this->tab === 'clusters' && $serviceId !== null ? $this->unclusteredCount($serviceId) : 0,
            'overview' => $overview,
            'clusterQueue' => $clusterQueue,
            'openCluster' => $openCluster,
            'affectedBrands' => $affected,
            'clusterSitePages' => $openCluster !== null ? $this->clusterSitePages((int) $openCluster->id) : [],
            'contentIdeas' => $openCluster !== null ? ContentIdea::query()->with(['originBrand:id,name', 'usages' => fn ($q) => $q->whereNotNull('page_id')->with('brand:id,name')])
                ->where('cluster_id', $openCluster->id)->where('status', 'active')->orderBy('id')->get() : collect(),
            'ideaStatus' => $ideaStatus,
            'brandPages' => $openCluster !== null && ctype_digit($this->brandId) && $affected->contains('id', (int) $this->brandId)
                ? Page::query()->whereIn('website_asset_id', DigitalAsset::query()->where('brand_id', (int) $this->brandId)->select('id'))
                    ->whereIn('category', ['hizmet', 'lokasyon', 'blog', 'sss'])->orderBy('path')->limit(500)->pluck('path', 'id')->all() : [],
            'terms' => $this->tab === 'filters' ? FilterTerm::query()->with('sector')
                ->when(ctype_digit($this->sector), fn ($q) => $q->where('sector_id', (int) $this->sector))
                ->orderBy('term')->paginate(50) : null,
            'pendingCount' => $this->pendingQuery(all: true)->count(),
            'pending' => $pending,
            'reviewCounts' => $reviewCounts,
            'reviewRunning' => $reviewRunning,
            'reviewItems' => $this->tab === 'deletions' ? $this->orderedReviewQuery()
                ->with(['searchQuery:id,text,impressions,sector_id', 'fromService.primaryName', 'toService.primaryName'])->paginate(self::PER_PAGE) : null,
            'reviewTerms' => $this->tab === 'deletions' && $this->reviewKind === QueryReviewItem::DELETE
                ? QueryReviewItem::query()->where('kind', QueryReviewItem::DELETE)->whereNull('kept_at')->whereNotNull('term')
                    ->groupBy('term')->selectRaw('term, count(*) as total')->orderBy('term')->pluck('total', 'term')->all() : [],
            'keptCount' => $this->tab === 'deletions' ? QueryReviewItem::query()->where('kind', $this->reviewKind)->whereNotNull('kept_at')->count() : 0,
            'negCatches' => $this->negOpen ? collect($this->negativeLines())->mapWithKeys(fn (string $term): array => [$term => QueryRuleProposer::catches($term, $this->negIds)])->all() : [],
            'keywordServices' => $this->tab === 'keywords' ? $this->keywordServices() : null,
            ...$this->keywordInsights(),
            'proposal' => $proposal,
            'clusterStatus' => is_array($clusterStatus) ? $clusterStatus : null,
            'polling' => ($proposal['status'] ?? null) === 'running' || ($clusterStatus['status'] ?? null) === 'running' || $this->negAwaiting
                || $overviewRunning || ($clusterQueue['status'] ?? null) === 'running' || ($ideaStatus['status'] ?? null) === 'running'
                || ($assign['status'] ?? null) === 'running' || ($filterProposal['status'] ?? null) === 'running' || ($scan['status'] ?? null) === 'running' || ($this->tab === 'deletions' && $reviewRunning),
        ]);
    }

    /** Bekleyenler page; its rows are checked against the filter basket and the library first (never listed if caught). */
    private function pendingList(): mixed
    {
        $page = fn () => $this->pendingQuery()->with(['brand:id,name', 'asset:id,name,type', 'service.primaryName'])
            ->orderByDesc('impressions')->orderBy('id')->paginate(50);
        $rows = $page();

        return app(PendingQueries::class)->prune($rows->pluck('id')->map(fn ($id): int => (int) $id)->all()) > 0 ? $page() : $rows;
    }

    private function queryList(): mixed
    {
        return $this->listQuery()->with(['service.primaryName', 'clusterLink.cluster:id,name'])
            ->orderByDesc('impressions')->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /** @return Builder<Query> the Sorgular tab's current filter (no order, no eager loads: also used for bulk updates) */
    private function listQuery(): Builder
    {
        return Query::query()->where('hidden', $this->hidden)
            ->when($this->variants && ! $this->hidden, fn (Builder $q) => $q->where('variant_head', true))
            ->when(ctype_digit($this->sector), fn (Builder $q) => $q->where('sector_id', (int) $this->sector))
            ->when($this->service === '__none', fn (Builder $q) => $q->whereNull('service_id'))
            ->when($this->service === '__any', fn (Builder $q) => $q->whereNotNull('service_id'))
            ->when(ctype_digit($this->service), fn (Builder $q) => $q->where('service_id', (int) $this->service))
            ->when($this->cluster === '__none', fn (Builder $q) => $q->whereNotIn('id', ClusterQuery::query()->select('query_id')))
            ->when(ctype_digit($this->cluster), fn (Builder $q) => $q->whereIn('id', ClusterQuery::query()->where('cluster_id', (int) $this->cluster)->select('query_id')))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('text', 'like', '%'.QueryNormalizer::lower(trim($this->search)).'%'));
    }

    /**
     * The bulk target: every matching query minus the unticked ones, or the ticked ids; with "Varyantları birleştir"
     * every variant of a targeted head too (hiding / assigning "implant" hides / assigns "diş implantı").
     *
     * @return Builder<Query>
     */
    private function targetQuery(): Builder
    {
        $rows = $this->rowTargetQuery();
        if (! $this->variants || $this->hidden) {
            return $rows;
        }

        return Query::query()->where('hidden', false)->where(fn (Builder $q) => $q->whereIn('id', (clone $rows)->select('id'))
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('queries as t')->whereIn('t.id', (clone $rows)->select('id'))
                ->whereRaw('COALESCE(t.sector_id, 0) = COALESCE(queries.sector_id, 0)')->whereColumn('t.variant_key', 'queries.variant_key')));
    }

    /** @return Builder<Query> the ticked / matching rows themselves (variant heads when merged) */
    private function rowTargetQuery(): Builder
    {
        return $this->selectAll
            ? $this->listQuery()->whereNotIn('id', array_map('intval', $this->excluded) ?: [0])
            : Query::query()->whereIn('id', $this->selectedIds() ?: [0]);
    }

    private function hasSelection(): bool
    {
        return $this->selectAll || $this->selectedIds() !== [];
    }

    /** @return list<int> up to $limit selected ids, most impressions first (AI calls that take a query list) */
    private function targetIds(int $limit): array
    {
        return $this->rowTargetQuery()->orderByDesc('impressions')->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Size and summed metrics of the variant groups of the listed heads ("sector|key" => …).
     *
     * @param  Collection<int, Query>  $heads
     * @return array<string, array{count: int, impressions: int, clicks: int}>
     */
    private function variantGroups(Collection $heads): array
    {
        $keys = $heads->pluck('variant_key')->filter()->unique()->values()->all();
        if ($keys === []) {
            return [];
        }

        return Query::query()->where('hidden', false)->whereIn('variant_key', $keys)
            ->groupBy('sector_id', 'variant_key')->selectRaw('sector_id, variant_key, count(*) as total, sum(impressions) as impressions, sum(clicks) as clicks')
            ->get()->mapWithKeys(fn ($row): array => [(int) $row->sector_id.'|'.$row->variant_key => [
                'count' => (int) $row->total, 'impressions' => (int) $row->impressions, 'clicks' => (int) $row->clicks,
            ]])->all();
    }

    /** @return array{queries: int, variants: int, topics: int} "N sorgu → V varyant → K konu" of the chosen sector (1 min cache) */
    private function ruleStats(): array
    {
        $sector = ctype_digit($this->sector) ? (int) $this->sector : null;

        return Cache::remember('queries.rule-stats.'.($sector ?? 'all').'.'.QueryRuleEngine::version(), 60, function () use ($sector): array {
            $row = DB::table('queries')->where('hidden', false)->where('is_suggested', false)
                ->when($sector !== null, fn ($q) => $q->where('sector_id', $sector))
                ->selectRaw("count(*) as total, count(distinct COALESCE(sector_id, 0) || ':' || variant_key) as variants, count(distinct COALESCE(sector_id, 0) || ':' || topic_key) as topics")
                ->first();

            return ['queries' => (int) $row->total, 'variants' => (int) $row->variants, 'topics' => (int) $row->topics];
        });
    }

    /** @return Collection<int, Cluster> */
    /** The service's clusters, biggest demand (sum of the members' impressions) first. */
    private function clusterList(int $serviceId): Collection
    {
        $demand = ClusterQuery::query()->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
            ->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')->where('clusters.service_id', $serviceId)
            ->groupBy('cluster_queries.cluster_id')->selectRaw('cluster_queries.cluster_id, sum(queries.impressions) as total')
            ->pluck('total', 'cluster_queries.cluster_id');

        return Cluster::query()->where('service_id', $serviceId)->with('mainQuery:id,text')->withCount('clusterQueries')->limit(300)->get()
            ->each(function (Cluster $cluster) use ($demand): void {
                $cluster->setAttribute('demand', (int) ($demand[$cluster->id] ?? 0));
            })
            ->sortBy([['demand', 'desc'], ['name', 'asc']])->values();
    }

    /**
     * "Bu kümeye atanmış sayfalar (tüm markalar)": every brand's page for the cluster with its nightly score
     * (ClusterPageScorer), scored pages first.
     *
     * @return list<array<string, mixed>>
     */
    private function clusterSitePages(int $clusterId): array
    {
        $scores = DB::table('cluster_page_scores')->where('cluster_id', $clusterId)->get()->keyBy('brand_cluster_page_id');

        return BrandClusterPage::query()->with(['brand:id,name', 'page:id,url'])->where('cluster_id', $clusterId)->where('excluded', false)
            ->whereNotNull('page_id')->get()
            ->map(fn (BrandClusterPage $row): array => ['brand' => (string) $row->brand?->name, 'site_id' => (int) $row->website_asset_id,
                'url' => (string) $row->page?->url, 'score' => $scores[$row->id] ?? null])
            ->sortBy([fn (array $a, array $b): int => [$b['score']?->score ?? -1, $a['brand']] <=> [$a['score']?->score ?? -1, $b['brand']]])
            ->values()->all();
    }

    /** Visible, real queries of the service in none of its clusters. */
    private function unclusteredCount(int $serviceId): int
    {
        return Query::query()->where('service_id', $serviceId)->where('hidden', false)->where('is_suggested', false)
            ->whereNotIn('id', ClusterQuery::query()->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')
                ->where('clusters.service_id', $serviceId)->select('cluster_queries.query_id'))
            ->count();
    }

    /**
     * Kümeler without a service filter: one row per active service with queries or clusters (of the chosen sector),
     * grouped by sector, biggest demand first: brands that offer it, queries, clustered queries, clusters (approved)
     * and the run state.
     *
     * @return array<string, list<array<string, mixed>>> sector name => rows
     */
    private function serviceOverview(): array
    {
        $code = ctype_digit($this->sector) ? ServiceCategory::query()->whereKey((int) $this->sector)->value('code') : null;
        $services = ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')
            ->when($code !== null, fn ($q) => $q->where('sector', $code))->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)->keyBy('id');
        if ($services->isEmpty()) {
            return [];
        }
        $ids = $services->keys()->all();
        $queries = Query::query()->whereIn('service_id', $ids)->where('hidden', false)->where('is_suggested', false)
            ->groupBy('service_id')->selectRaw('service_id, count(*) as total, sum(impressions) as demand')->get()->keyBy('service_id');
        $clustered = ClusterQuery::query()->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')
            ->join('queries', 'queries.id', '=', 'cluster_queries.query_id')->whereIn('clusters.service_id', $ids)
            ->where('queries.hidden', false)->where('queries.is_suggested', false)
            ->groupBy('clusters.service_id')->selectRaw('clusters.service_id, count(*) as total')->pluck('total', 'clusters.service_id');
        $clusters = Cluster::query()->whereIn('service_id', $ids)->groupBy('service_id')
            ->selectRaw('service_id, count(*) as total, sum(case when approved then 1 else 0 end) as approved')->get()->keyBy('service_id');
        $brands = BrandOffering::query()->join('brands', 'brands.id', '=', 'brand_offerings.brand_id')
            ->whereIn('brand_offerings.service_catalog_item_id', $ids)->where('brand_offerings.status', OfferingStatus::Active->value)
            ->whereNull('brands.deleted_at')->distinct()->orderBy('brands.name')
            ->get(['brand_offerings.service_catalog_item_id', 'brands.name'])
            ->groupBy('service_catalog_item_id')->map(fn ($rows) => $rows->pluck('name')->unique()->values()->all());
        $states = Cache::many(array_map(fn (int $id): string => QueryClusterer::cacheKey($id), $ids));
        $sectorNames = ServiceCategory::query()->pluck('name', 'code');

        $rows = [];
        foreach ($services as $id => $service) {
            $total = (int) ($queries[$id]->total ?? 0);
            $clusterCount = (int) ($clusters[$id]->total ?? 0);
            if ($total === 0 && $clusterCount === 0) {
                continue;
            }
            $state = $states[QueryClusterer::cacheKey((int) $id)] ?? null;
            $rows[(string) ($sectorNames[$service->sector] ?? 'Sektörsüz')][] = [
                'id' => (int) $id,
                'name' => (string) $service->primaryName->raw_label,
                'brands' => $brands[$id] ?? [],
                'queries' => $total,
                'demand' => (int) ($queries[$id]->demand ?? 0),
                'clustered' => (int) ($clustered[$id] ?? 0),
                'clusters' => $clusterCount,
                'approved' => (int) ($clusters[$id]->approved ?? 0),
                'state' => is_array($state) ? $state : null,
            ];
        }
        ksort($rows);
        foreach ($rows as &$sectorRows) {
            usort($sectorRows, fn (array $a, array $b): int => [$b['demand'], $a['name']] <=> [$a['demand'], $b['name']]);
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function serviceOptions(): array
    {
        $code = ctype_digit($this->sector) ? ServiceCategory::query()->whereKey((int) $this->sector)->value('code') : null;

        return ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')
            ->when($code !== null, fn ($q) => $q->where('sector', $code))
            ->limit(500)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(int) $item->id => (string) $item->primaryName->raw_label])
            ->sort()->all();
    }

    /**
     * Eşleme kelimeleri sub-views: Kelime önerileri (one page), Çakışmalar (paginated lines + their keywords), Sektör
     * uyumu (pairs), and the counts on the sub-view buttons.
     *
     * @return array<string, mixed>
     */
    private function keywordInsights(): array
    {
        $data = ['suggestions' => null, 'suggestPages' => 0, 'conflictRows' => null, 'conflictKeywords' => [], 'conflictNames' => [], 'mismatches' => null, 'keywordCounts' => []];
        if ($this->tab !== 'keywords') {
            return $data;
        }
        $insights = app(KeywordInsights::class);
        $sectorId = $this->sectorId();
        if ($sectorId !== null) {
            $data['keywordCounts']['conflicts'] = $this->conflictQuery()->count();
        }
        if ($this->keywordView === 'suggestions' && $sectorId !== null) {
            $open = $insights->openSuggestions($sectorId);
            $data['suggestPages'] = (int) ceil(count($open) / KeywordInsights::SUGGESTIONS_PER_PAGE);
            $page = min($this->suggestPage, max(0, $data['suggestPages'] - 1));
            $data['suggestions'] = array_slice($open, $page * KeywordInsights::SUGGESTIONS_PER_PAGE, KeywordInsights::SUGGESTIONS_PER_PAGE);
        }
        if ($this->keywordView === 'conflicts' && $sectorId !== null) {
            $rows = $this->conflictQuery()->with('service.primaryName')->orderByDesc('impressions')->orderBy('id')->paginate(self::PER_PAGE);
            $keywords = array_intersect_key($insights->conflicts($sectorId), array_flip($rows->pluck('id')->map(fn ($id): int => (int) $id)->all()));
            $data['conflictRows'] = $rows;
            $data['conflictKeywords'] = $keywords;
            $data['conflictNames'] = KeywordInsights::serviceNames(collect($keywords)->flatten(1)->pluck('service')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        }
        $mismatches = $insights->sectorMismatches($sectorId);
        $data['keywordCounts']['sectors'] = array_sum(array_column($mismatches, 'total'));
        $data['mismatches'] = $this->keywordView === 'sectors' ? $mismatches : null;

        return $data;
    }

    /** @return Collection<int, ServiceCatalogItem>|null */
    private function keywordServices(): ?Collection
    {
        $code = ctype_digit($this->sector) ? ServiceCategory::query()->whereKey((int) $this->sector)->value('code') : null;
        if ($code === null) {
            return null;
        }

        return ServiceCatalogItem::query()->with(['primaryName', 'matchingKeywords' => fn ($q) => $q->orderBy('label')])
            ->where('sector', $code)->where('status', 'active')->limit(300)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->sortBy(fn (ServiceCatalogItem $item): string => (string) $item->primaryName->raw_label)->values();
    }

    private function fillClusterForm(Cluster $cluster): void
    {
        $this->clusterForm = [
            'name' => (string) $cluster->name, 'intent' => (string) $cluster->intent, 'page_type' => (string) $cluster->page_type,
            'user_need' => (string) ($cluster->user_need ?? ''), 'main_query_id' => (string) ($cluster->main_query_id ?? ''),
            'representative_query_ids' => array_map('strval', (array) $cluster->representative_query_ids),
            'subtopics' => implode("\n", (array) $cluster->subtopics), 'exclusions' => implode("\n", (array) $cluster->exclusions),
        ];
    }

    private function openedCluster(): Cluster
    {
        abort_if($this->openClusterId === null, 404);

        return Cluster::query()->findOrFail($this->openClusterId);
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->selected), fn (int $id): bool => $id > 0)));
    }

    /** @return list<int> */
    private function clusterQueryIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->selectedClusterQueries), fn (int $id): bool => $id > 0)));
    }

    private function actor(): User
    {
        $this->resetErrorBag();
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
