<?php

namespace App\Livewire\Operator\Website;

use App\Models\ContentArticle;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\TopicCluster;
use App\Models\TopicClusterQuery;
use App\Services\ContentDelivery\ContentComplianceGate;
use App\Services\ContentStudio\ArticleWriter;
use App\Services\ContentStudio\ContentIdeaAi;
use App\Services\ContentStudio\ContentIdeaPlanner;
use App\Services\ContentStudio\ContentStudio;
use App\Services\ContentStudio\TopicMapBuilder;
use App\Services\ContentStudio\TopicMapEditor;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Support\Permissions;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Website › İçerik Stüdyosu (SEO content pipeline Faz 3–4).
 *
 *  Konu haritası: hub queries grouped by service and clustered by topic; each cluster has one verdict (Gerek yok /
 *  Güçlendir / Yeni içerik / Birleştir) and can be renamed, merged, split, re-assigned or skipped.
 *  Konu fikirleri: concrete ideas from uncovered topics, service areas, "Konu üret" (AI gap ideation) or the operator.
 *  Yazılar: queued AI articles with progress, language versions, post dates, preview / edit, WordPress drafts or XML.
 */
final class ContentStudioPanel extends Component
{
    #[Locked]
    public int $websiteId;

    #[Url(as: 'studio_view')]
    public string $view = 'topics';

    #[Url(as: 'studio_cluster')]
    public ?int $focusCluster = null;

    #[Url(as: 'studio_offering')]
    public ?int $focusOffering = null;

    #[Url(as: 'studio_area')]
    public ?string $focusArea = null;

    #[Url(as: 'studio_service')]
    public string $serviceFilter = '';

    #[Url(as: 'studio_verdict')]
    public string $verdictFilter = 'actionable';

    public ?int $highlightIdea = null;

    public ?int $renaming = null;

    public string $clusterLabel = '';

    public ?int $openCluster = null;

    /** @var array<int|string, string> cluster id => merge target id */
    public array $mergeTarget = [];

    /** @var array<int|string, string> query row id => target cluster id */
    public array $moveTarget = [];

    /** @var array<int|string, array<int|string, bool>> cluster id => [query row id => selected] */
    public array $splitSelection = [];

    /** @var array<int|string, string> */
    public array $splitLabel = [];

    /** @var array<int|string, bool> idea id => selected */
    public array $selectedIdeas = [];

    /** @var array<int|string, array{title?: string, focus?: string}> */
    public array $ideaEdits = [];

    public string $newIdeaTitle = '';

    public string $newIdeaOffering = '';

    /** @var array<int|string, bool> offering id => chosen axis */
    public array $axisOfferings = [];

    public int $axisCount = 30;

    public bool $confirmingBulk = false;

    public bool $translate = true;

    /** @var array<int|string, bool> article id => selected */
    public array $selectedArticles = [];

    public ?int $previewArticle = null;

    /** @var array{title?: string, meta_title?: string, meta_description?: string, html?: string} */
    public array $articleEdit = [];

    public string $scheduleStart = '';

    public string $scheduleTime = '10:00';

    public int $schedulePerDay = 1;

    public string $message = '';

    public string $tone = 'success';

    public function mount(int $websiteId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->websiteId = $websiteId;
        $this->scheduleStart = now()->addDay()->toDateString();
        $this->scheduleTime = (string) config('moxdop-content.schedule.default_time', '10:00');
        $this->schedulePerDay = (int) config('moxdop-content.schedule.default_per_day', 1);
        if (! in_array($this->view, ['topics', 'ideas', 'articles'], true)) {
            $this->view = 'topics';
        }
        if (! app(ServiceScope::class)->isAssetOperational($websiteId)) {
            return;
        }
        // Deep link from an SEO task ("Stüdyoda hazırla").
        try {
            if ($this->focusCluster !== null) {
                $cluster = TopicCluster::query()->where('digital_asset_id', $websiteId)->find($this->focusCluster);
                if ($cluster !== null) {
                    $idea = app(ContentIdeaPlanner::class)->ideaForCluster($cluster)['idea'];
                    $this->view = $idea !== null ? 'ideas' : 'topics';
                    $this->highlightIdea = $idea?->id;
                    $this->openCluster = $idea === null ? $cluster->id : null;
                    if ($idea !== null) {
                        $this->selectedIdeas = [$idea->id => true];
                    }
                }
            } elseif ($this->focusOffering !== null) {
                $ideas = app(ContentIdeaPlanner::class)->locationIdeas($this->site(), [$this->focusOffering], (int) config('moxdop-content.ideas.location_ideas_per_run', 6));
                $idea = collect($ideas)->first(fn (ContentIdea $i): bool => $this->focusArea === null || mb_strtolower((string) $i->location) === mb_strtolower($this->focusArea)) ?? ($ideas[0] ?? null);
                $this->view = 'ideas';
                $this->highlightIdea = $idea?->id;
                if ($idea !== null) {
                    $this->selectedIdeas = [$idea->id => true];
                }
            }
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['topics', 'ideas', 'articles'], true) ? $view : 'topics';
    }

    // ------------------------------------------------------------ topic map

    public function rebuildMap(TopicMapBuilder $builder): void
    {
        try {
            $builder->queue($this->site(), 'manual');
            $this->say('Konu haritası yenileniyor; bitince liste kendiliğinden güncellenir.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function toggleCluster(int $clusterId): void
    {
        $this->openCluster = $this->openCluster === $clusterId ? null : $clusterId;
    }

    public function startRename(int $clusterId): void
    {
        $this->renaming = $clusterId;
        $this->clusterLabel = (string) $this->cluster($clusterId)->label;
    }

    public function saveRename(TopicMapEditor $editor): void
    {
        if ($this->renaming === null) {
            return;
        }
        try {
            $editor->rename($this->cluster($this->renaming), $this->clusterLabel, auth()->user());
            $this->renaming = null;
            $this->say('Küme adı kaydedildi; yenilemelerde korunur.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function skipCluster(int $clusterId, TopicMapEditor $editor): void
    {
        $cluster = $this->cluster($clusterId);
        $editor->skip($cluster, $cluster->status !== 'skipped', auth()->user());
        $this->say($cluster->status === 'skipped' ? 'Küme atlandı; öneri ve görev üretmez.' : 'Küme yeniden haritada.');
    }

    public function mergeCluster(int $clusterId, TopicMapEditor $editor): void
    {
        $target = (int) ($this->mergeTarget[$clusterId] ?? 0);
        try {
            $editor->merge($this->cluster($clusterId), $this->cluster($target), auth()->user());
            unset($this->mergeTarget[$clusterId]);
            $this->openCluster = $target;
            $this->say('Kümeler birleştirildi; sorgular birleşen kümede sabitlendi.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function moveQuery(int $queryRowId, TopicMapEditor $editor): void
    {
        $row = TopicClusterQuery::query()->where('digital_asset_id', $this->websiteId)->findOrFail($queryRowId);
        $target = (int) ($this->moveTarget[$queryRowId] ?? 0);
        try {
            $editor->moveQuery($row, $this->cluster($target), auth()->user());
            unset($this->moveTarget[$queryRowId]);
            $this->say('Sorgu taşındı; yenilemelerde bu kümede kalır.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function splitCluster(int $clusterId, TopicMapEditor $editor): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->splitSelection[$clusterId] ?? [])));
        try {
            $new = $editor->split($this->cluster($clusterId), $ids, (string) ($this->splitLabel[$clusterId] ?? ''), auth()->user());
            unset($this->splitSelection[$clusterId], $this->splitLabel[$clusterId]);
            $this->openCluster = $new->id;
            $this->say('Seçilen sorgular yeni küme oldu: '.$new->label.'.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function ideaFromCluster(int $clusterId, ContentIdeaPlanner $planner): void
    {
        try {
            app(ServiceScope::class)->ensureAssetServed($this->websiteId, 'studio');
            $result = $planner->ideaForCluster($this->cluster($clusterId));
            if ($result['idea'] === null) {
                $this->say('Bu konu sitede zaten yazılı; yeni fikir önerilmedi.', 'error');

                return;
            }
            $this->highlightIdea = $result['idea']->id;
            $this->selectedIdeas[$result['idea']->id] = true;
            $this->view = 'ideas';
            $this->say('Fikir hazır: '.$result['idea']->title.'.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function prepareUpdate(int $clusterId, ContentStudio $studio): void
    {
        try {
            $studio->prepareUpdate($this->cluster($clusterId));
            $this->say('Güncelleme taslağı hazırlanıyor (AI). Metni “Düzeltmeler” sekmesinde inceleyip WordPress’e taslak olarak gönderebilirsin.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    // --------------------------------------------------------------- ideas

    public function proposeIdeas(ContentIdeaPlanner $planner): void
    {
        try {
            app(ServiceScope::class)->ensureAssetServed($this->websiteId, 'studio');
            $result = $planner->fromClusters($this->site(), $this->serviceFilter !== '' ? [(int) $this->serviceFilter] : null);
            $this->view = 'ideas';
            $this->say($result['created'].' yeni fikir eklendi (konu haritası ve hizmet bölgelerinden).'.($result['skipped_existing'] > 0 ? ' '.$result['skipped_existing'].' konu sitede zaten yazılı olduğu için önerilmedi.' : ''));
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function generateIdeas(ContentIdeaAi $ai): void
    {
        try {
            $ai->queue($this->site(), array_map('intval', array_keys(array_filter($this->axisOfferings))), $this->axisCount, auth()->user());
            $this->view = 'ideas';
            $this->say('Konular hazırlanıyor: önce konu haritasından, eksik kalanlar AI ile (tek istekte).');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function addIdea(ContentIdeaPlanner $planner): void
    {
        try {
            $idea = $planner->addManual($this->site(), $this->newIdeaTitle, $this->newIdeaOffering !== '' ? (int) $this->newIdeaOffering : null, auth()->user());
            $this->newIdeaTitle = '';
            $this->highlightIdea = $idea->id;
            $this->say('Fikir eklendi.'.($idea->similar_existing !== null ? ' Benzer mevcut yazı: '.data_get($idea->similar_existing, 'title').'.' : ''));
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function editIdea(int $ideaId): void
    {
        $idea = $this->idea($ideaId);
        $this->ideaEdits[$ideaId] = ['title' => (string) $idea->title, 'focus' => (string) $idea->focus_keyword];
    }

    public function saveIdea(int $ideaId, ContentIdeaPlanner $planner): void
    {
        try {
            $planner->update($this->idea($ideaId), (string) ($this->ideaEdits[$ideaId]['title'] ?? ''), (string) ($this->ideaEdits[$ideaId]['focus'] ?? ''));
            unset($this->ideaEdits[$ideaId]);
            $this->say('Fikir kaydedildi.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function removeIdea(int $ideaId): void
    {
        $this->idea($ideaId)->forceFill(['status' => 'removed'])->save();
        unset($this->selectedIdeas[$ideaId]);
        $this->say('Fikir listeden kaldırıldı; tekrar önerilmez.');
    }

    public function writeIdea(int $ideaId, ContentStudio $studio): void
    {
        $this->queueWrite([$ideaId], $studio);
    }

    public function confirmBulk(): void
    {
        if (array_filter($this->selectedIdeas) === []) {
            $this->say('Önce konu seç.', 'error');

            return;
        }
        $this->confirmingBulk = true;
    }

    public function writeSelected(ContentStudio $studio): void
    {
        $this->confirmingBulk = false;
        $this->queueWrite(array_map('intval', array_keys(array_filter($this->selectedIdeas))), $studio);
    }

    // ------------------------------------------------------------ articles

    public function openArticle(int $articleId): void
    {
        $article = $this->article($articleId);
        $this->previewArticle = $this->previewArticle === $articleId ? null : $articleId;
        $this->articleEdit = [
            'title' => (string) data_get($article->payload, 'title', $article->title), 'meta_title' => (string) data_get($article->payload, 'meta_title', ''),
            'meta_description' => (string) data_get($article->payload, 'meta_description', ''), 'html' => (string) data_get($article->payload, 'html', ''),
        ];
    }

    public function saveArticle(int $articleId, ArticleWriter $writer): void
    {
        try {
            $article = $writer->edit($this->article($articleId), $this->articleEdit);
            $blocking = $article->blockingViolations();
            $this->say($blocking === [] ? 'Yazı kaydedildi; uyum kontrolünden geçti.' : 'Yazı kaydedildi ama hâlâ uyum kuralına takılıyor: '.ContentComplianceGate::summary($blocking).'.', $blocking === [] ? 'success' : 'error');
        } catch (RuntimeException $exception) {
            $this->say($exception->getMessage(), 'error');
        }
    }

    public function rewriteArticle(int $articleId, ContentStudio $studio): void
    {
        try {
            $studio->rewrite($this->article($articleId));
            $this->say('Yazı yeniden yazılıyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function translateArticle(int $articleId, ContentStudio $studio): void
    {
        try {
            $site = $this->site();
            $count = $studio->translate($this->article($articleId), array_column($studio->languages($site)['targets'], 'slug'));
            $this->say($count.' dilde hazırlanıyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function scheduleSelected(ContentStudio $studio): void
    {
        try {
            $count = $studio->schedule($this->site(), $this->selectedArticleIds(), $this->scheduleStart, $this->scheduleTime, $this->schedulePerDay);
            $this->say($count.' yazıya tarih atandı (taslak olarak kalırlar).');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function publishArticle(int $articleId, ContentStudio $studio): void
    {
        if (! ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_WORDPRESS)) {
            $this->say('WordPress’e göndermeyi yalnız Admin onaylayabilir.', 'error');

            return;
        }
        try {
            $action = $studio->publish(auth()->user(), $this->article($articleId));
            $this->say('WordPress’e taslak olarak gönderiliyor ('.count((array) data_get($action->request_payload, 'drafts', [])).' dil). Yayındaki içerik değişmez; geri alınabilir.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function publishSelected(ContentStudio $studio): void
    {
        if (! ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_WORDPRESS)) {
            $this->say('WordPress’e göndermeyi yalnız Admin onaylayabilir.', 'error');

            return;
        }
        $sent = 0;
        $errors = [];
        foreach ($this->selectedArticleIds() as $id) {
            try {
                $studio->publish(auth()->user(), $this->article($id));
                $sent++;
            } catch (ValidationException $exception) {
                $errors[] = (string) collect($exception->errors())->flatten()->first();
            }
        }
        $this->say($sent.' yazı WordPress’e taslak olarak gönderiliyor.'.($errors !== [] ? ' Gönderilemeyen: '.implode(' ', array_slice($errors, 0, 3)) : ''), $errors === [] ? 'success' : 'error');
    }

    public function render(ContentStudio $studio, TopicMapBuilder $builder, ContentIdeaAi $ideaAi): View
    {
        $site = $this->site();
        $served = app(ServiceScope::class)->isAssetOperational($site->id);
        $services = TopicMapBuilder::serviceNames((int) $site->brand_id);
        $allClusters = TopicCluster::query()->where('digital_asset_id', $site->id)->whereIn('status', ['active', 'skipped'])->get(['id', 'label', 'verdict', 'status', 'brand_offering_id']);
        $clusters = TopicCluster::query()->where('digital_asset_id', $site->id)
            ->when($this->verdictFilter === 'skipped', fn ($q) => $q->where('status', 'skipped'), fn ($q) => $q->where('status', 'active'))
            ->when($this->verdictFilter === 'actionable', fn ($q) => $q->whereIn('verdict', ['new', 'strengthen', 'merge']))
            ->when(in_array($this->verdictFilter, ['none', 'strengthen', 'new', 'merge'], true), fn ($q) => $q->where('verdict', $this->verdictFilter))
            ->when($this->serviceFilter !== '', fn ($q) => $this->serviceFilter === 'none' ? $q->whereNull('brand_offering_id') : $q->where('brand_offering_id', (int) $this->serviceFilter))
            ->with(['queries' => fn ($q) => $q->limit(40)])
            ->orderByDesc('demand_score')->limit(80)->get();
        $ideas = ContentIdea::query()->where('digital_asset_id', $site->id)->where('status', '!=', 'removed')
            ->when($this->serviceFilter !== '' && $this->serviceFilter !== 'none', fn ($q) => $q->where('brand_offering_id', (int) $this->serviceFilter))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'writing' THEN 1 ELSE 2 END")->orderBy('sort')->limit(200)->get();
        $articles = ContentArticle::query()->where('digital_asset_id', $site->id)->whereNull('source_article_id')->with(['translations', 'writeAction'])
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')->orderBy('scheduled_at')->orderByDesc('id')->limit(200)->get();
        $languages = $studio->languages($site);
        $selectedCount = count(array_filter($this->selectedIdeas));
        $build = $builder->latest($site);
        $ideaState = $ideaAi->state($site->id);
        $writing = ContentArticle::query()->where('digital_asset_id', $site->id)->where('status', 'writing')->count();
        $recentBatch = ContentArticle::query()->where('digital_asset_id', $site->id)->whereNotNull('batch')->latest('id')->value('batch');
        $batchTotal = $recentBatch !== null ? ContentArticle::query()->where('digital_asset_id', $site->id)->where('batch', $recentBatch)->count() : 0;
        $selectedArticles = $this->selectedArticleIds();

        return view('livewire.operator.website.content-studio-panel', [
            'site' => $site,
            'served' => $served,
            'services' => $services,
            'build' => $build,
            'clusters' => $clusters,
            'allClusters' => $allClusters,
            'verdictCounts' => TopicCluster::query()->where('digital_asset_id', $site->id)->where('status', 'active')->selectRaw('verdict, count(*) as c')->groupBy('verdict')->pluck('c', 'verdict')->all(),
            'ideas' => $ideas,
            'articles' => $articles,
            'languages' => $languages,
            'selectedCount' => $selectedCount,
            'estimate' => $studio->estimate(max(1, $selectedCount), $this->translate ? count($languages['targets']) : 0),
            'ideaEstimate' => $ideaAi->estimate(max(1, $this->axisCount)),
            'ideaState' => $ideaState,
            'writing' => $writing,
            'batchTotal' => $batchTotal,
            'batchDone' => $recentBatch !== null ? $batchTotal - ContentArticle::query()->where('digital_asset_id', $site->id)->where('batch', $recentBatch)->where('status', 'writing')->count() : 0,
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_WORDPRESS),
            'exportUrl' => $selectedArticles !== [] ? route('operator.website.wxr-export', ['site' => $site->id, 'articles' => implode(',', $selectedArticles)]) : null,
            'polling' => ($build?->isRunning() ?? false) || $ideaState === 'running' || $writing > 0,
        ]);
    }

    /** @param  list<int>  $ideaIds */
    private function queueWrite(array $ideaIds, ContentStudio $studio): void
    {
        try {
            $site = $this->site();
            $languages = $this->translate ? array_column($studio->languages($site)['targets'], 'slug') : [];
            $result = $studio->queueWrite($site, $ideaIds, $languages, auth()->user());
            foreach ($ideaIds as $id) {
                unset($this->selectedIdeas[$id]);
            }
            $this->view = 'articles';
            $this->say($result['queued'].' yazı hazırlanıyor'.($languages !== [] ? ' (+ '.implode(', ', $languages).' sürümleri)' : '').'. İlerleme aşağıda görünür.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    /** @return list<int> */
    private function selectedArticleIds(): array
    {
        return array_map('intval', array_keys(array_filter($this->selectedArticles)));
    }

    private function site(): DigitalAsset
    {
        return DigitalAsset::query()->where('type', 'website')->findOrFail($this->websiteId);
    }

    private function cluster(int $id): TopicCluster
    {
        return TopicCluster::query()->where('digital_asset_id', $this->websiteId)->findOrFail($id);
    }

    private function idea(int $id): ContentIdea
    {
        return ContentIdea::query()->where('digital_asset_id', $this->websiteId)->findOrFail($id);
    }

    private function article(int $id): ContentArticle
    {
        return ContentArticle::query()->where('digital_asset_id', $this->websiteId)->findOrFail($id);
    }

    private function sayError(ValidationException $exception): void
    {
        $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
    }

    private function say(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->tone = $tone;
    }
}
