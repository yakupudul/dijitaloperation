<?php

namespace App\Livewire\Demo\Gbp;

use App\Contracts\GbpOperatorWorkspace;
use App\Jobs\Gbp\SyncGbpSuggestionsJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Livewire\Operator\Concerns\HasDateRange;
use App\Models\AiProduction;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\GbpReview;
use App\Models\Suggestion;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Archive\ProductionArchive;
use App\Services\Async\AsyncOperationService;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\DeskChecks;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpPeerProfiles;
use App\Services\Gbp\GbpPostQueue;
use App\Services\Gbp\GbpProfilePlanner;
use App\Services\Gbp\GbpScreen;
use App\Services\Gbp\GbpStandardInput;
use App\Services\Gbp\GbpSuggestions;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SiteRange;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * İşletme Profili (Faz 7): Genel Bakış · Yapılacaklar · Yorumlar · Gönderiler · Kategori ve hizmetler · Analiz · Ayarlar
 * for one bound location. Numbers come from the collected gbp_* tables (GbpScreen); suggestions from the ONE
 * suggestions table (GbpSuggestions); AI work is queued (GbpAssistant, ReviewReplyDrafter, GbpProfilePlanner). Writes
 * to Google are Admin-approved, recorded and undoable through ExternalWriteService: ADR-073 a review reply and a post
 * (now or scheduled), ADR-077 adding categories / services from a prepared plan. Description, hours, primary
 * category and removals are changed by the operator on Google.
 */
#[Layout('operator.layouts.app')]
#[Title('İşletme Profili')]
class OverviewPage extends Component
{
    use HasDateRange;
    use ResolvesCanonicalOperatorAsset;

    public const array TABS = ['overview' => 'Genel Bakış', 'todo' => 'Yapılacaklar', 'reviews' => 'Yorumlar', 'posts' => 'Gönderiler', 'services' => 'Kategori ve hizmetler', 'analysis' => 'Analiz', 'settings' => 'Ayarlar'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const array LEGACY_TAB_MAP = [
        'performance' => 'analysis', 'queries' => 'analysis', 'visibility' => 'analysis',
        'profile' => 'todo', 'health' => 'todo', 'advisor' => 'todo', 'setup' => 'settings', 'collect' => 'reviews',
        'insights' => 'overview', 'competitors' => 'overview', 'operations' => 'overview',
    ];

    #[Locked]
    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    /** Date picker (Genel Bakış, Analiz): preset days, or a custom start / end (HasDateRange), and the comparison. */
    #[Url]
    public int $days = 28;

    #[Url(as: 'kars')]
    public string $compare = SiteRange::COMPARE_PREVIOUS;

    #[Url]
    public bool $unanswered = false;

    /** Gönderiler form. @var array{body: string, url: string, action_type: string, when: string, publish_at: string} */
    public array $post = ['body' => '', 'url' => '', 'action_type' => 'LEARN_MORE', 'when' => 'now', 'publish_at' => ''];

    public bool $postFormOpen = false;

    /** Siteden paylaş: selected page id. */
    public string $sharePageId = '';

    /** Kategori ve hizmetler: the operator's lists (one per line). */
    public string $wantCategories = '';

    public string $wantServices = '';

    /** "Aynı sektördeki işletmelerden getir" panel and its picked rows (GbpPeerProfiles keys). */
    public bool $peerOpen = false;

    /** @var list<string> */
    public array $peerPick = [];

    /** Plan rows chosen to send. @var list<string> category ids */
    public array $pickCategories = [];

    /** @var list<int> service indexes */
    public array $pickServices = [];

    /** Edited service descriptions by index. @var array<int, string> */
    public array $serviceText = [];

    /** Plan the choices above belong to. */
    public ?int $planId = null;

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['google_business_profile', 'gbp']);
        $this->normalize();
        $asset = $this->asset();
        // First visit: the system checks are computed once in the background (then daily).
        if ($asset->brand_id !== null && Cache::add('gbp-suggestions-seeded:'.$asset->id, true, now()->addHour())
            && Suggestion::query()->where('target_type', GbpSuggestions::TARGET)->where('target_id', $asset->id)->doesntExist()) {
            SyncGbpSuggestionsJob::dispatch((int) $asset->id);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalize();
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->asset(), auth()->user(), ['trigger' => 'operator.gbp.refresh']);
        DemoState::flash((string) ($result['message'] ?? __('operator_runtime.sources.collect_failed')), ($result['ok'] ?? false) ? 'success' : 'info');
    }

    /* ---------------- Yapılacaklar ---------------- */

    public function recheck(): void
    {
        SyncGbpSuggestionsJob::dispatch((int) $this->asset()->id);
        DemoState::flash('Profil standartları yeniden kontrol ediliyor.', 'info');
    }

    public function compareServices(GbpAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GbpAssistant::OP_SERVICES);
    }

    public function proposeDescription(GbpAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GbpAssistant::OP_DESCRIPTION);
    }

    public function approveSuggestion(int $id, GbpSuggestions $suggestions): void
    {
        $suggestions->approve($suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Onaylandı; Google’da yapınca “Uygulandı” deyin.', 'success');
    }

    /** The operator made the change on Google: applied with the outcome baseline. */
    public function markApplied(int $id, GbpSuggestions $suggestions): void
    {
        $suggestion = $suggestions->find($this->asset(), $id);
        abort_unless($suggestion->status === Suggestion::APPROVED, 404);
        $suggestions->markApplied($suggestion, auth()->user());
        DemoState::flash('Uygulandı olarak işaretlendi.', 'success');
    }

    public function dismissSuggestion(int $id, GbpSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->dismiss($suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Reddedildi.', 'info');
    }

    public function snoozeSuggestion(int $id, GbpSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->snooze($suggestions->find($this->asset(), $id), 7);
        DemoState::flash('7 gün ertelendi.', 'info');
    }

    /* ---------------- Yorumlar ---------------- */

    public function showUnanswered(): void
    {
        $this->unanswered = true;
        $this->setTab('reviews');
    }

    public function draftReply(int $reviewId, ReviewReplyDrafter $drafter): void
    {
        if (! (bool) $this->asset()->loadMissing('brand.customer')->brand?->isOperational()) {
            DemoState::flash('Marka operasyonel değil; AI çalışmaz.', 'error');

            return;
        }
        try {
            $drafter->queue($this->review($reviewId));
            DemoState::flash('Yanıt taslağı hazırlanıyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** ADR-073: the Admin-approved reply goes to Google (undo from the list). */
    public function publishReply(int $reviewId, string $text, ExternalWriteService $writes): void
    {
        try {
            $writes->requestReviewReply(auth()->user(), $this->review($reviewId), $text);
            DemoState::flash('Yanıt Google’a gönderiliyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** ADR-073 undo of a reply or a post of this profile (Admin). */
    public function undoWrite(int $actionId, ExternalWriteService $writes): void
    {
        try {
            $writes->requestUndo(auth()->user(), $this->writeAction($actionId));
            DemoState::flash('Geri alınıyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /* ---------------- Gönderiler ---------------- */

    public function startPost(): void
    {
        $this->post = ['body' => '', 'url' => '', 'action_type' => 'LEARN_MORE', 'when' => 'now', 'publish_at' => ''];
        $this->postFormOpen = true;
        $this->resetValidation();
    }

    public function cancelPost(): void
    {
        $this->postFormOpen = false;
        $this->resetValidation();
    }

    public function sharePage(GbpAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GbpAssistant::OP_POST, ['page_id' => (int) $this->sharePageId]);
    }

    /** Gönderiler › takvim: approves one planned post of this location. */
    public function approvePlanned(int $id, GbpPostQueue $queue): void
    {
        $queue->approve(auth()->user(), [$this->planned($id)->id]);
    }

    /** Gönderiler › takvim: approves every waiting post of this location's next 30 days. */
    public function approveAllPlanned(GbpPostQueue $queue): void
    {
        $count = $queue->approveAll(auth()->user(), (int) $this->asset()->id);
        DemoState::flash($count > 0 ? $count.' gönderi onaylandı; günü gelince yayınlanır.' : 'Onay bekleyen gönderi yok.');
    }

    /** Gönderiler › takvim: leaves a day out; the next planning run fills it again. */
    public function skipPlanned(int $id, GbpPostQueue $queue): void
    {
        $queue->skip(auth()->user(), $this->planned($id));
        DemoState::flash('Atlandı; o gün bir sonraki planlamada yeniden dolar.');
    }

    /** Loads the page-post AI draft into the form (the operator edits it before approving). */
    public function useAiDraft(int $productionId, ProductionArchive $archive): void
    {
        $draft = AiProduction::query()->whereKey($productionId)->where('kind', GbpAssistant::POST_KIND)
            ->where('subject_type', 'DigitalAsset')->where('subject_id', $this->asset()->id)->firstOrFail();
        $this->startPost();
        $this->post['body'] = mb_substr((string) data_get($draft->content, 'body'), 0, GbpAssistant::POST_MAX);
        $this->post['url'] = (string) data_get($draft->content, 'url', '');
        $this->post['action_type'] = (string) (data_get($draft->content, 'action_type') ?: 'LEARN_MORE');
        $archive->mark($draft, AiProduction::STATUS_USED, auth()->user());
    }

    /** ADR-073: the Admin-approved post goes to Google now or at the chosen time (undo / cancel from the list). */
    public function publishPost(ExternalWriteService $writes): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        $data = $this->validate([
            'post.body' => ['required', 'string', 'max:'.GbpAssistant::POST_MAX],
            'post.url' => ['nullable', 'url', 'max:500'],
            'post.action_type' => ['required', 'in:LEARN_MORE,BOOK,CALL,ORDER,SIGN_UP'],
            'post.when' => ['required', 'in:now,later'],
            'post.publish_at' => ['required_if:post.when,later', 'nullable', 'date'],
        ], [], ['post.body' => 'metin', 'post.url' => 'bağlantı', 'post.publish_at' => 'yayın zamanı'])['post'];
        $blocking = GbpAssistant::blockingHits($this->asset()->loadMissing('brand')->brand, (string) $data['body']);
        if ($blocking !== []) {
            $this->addError('post.body', 'Sektör uyum kuralına takılıyor: '.implode(', ', $blocking).'.');

            return;
        }
        try {
            $action = $writes->requestLocalPost(auth()->user(), $this->asset(), [
                'summary' => (string) $data['body'], 'url' => ($data['url'] ?? '') ?: null, 'action_type' => $data['action_type'],
                'publish_at' => $data['when'] === 'later' ? (string) $data['publish_at'] : null,
            ]);
            $this->postFormOpen = false;
            $action->refresh();
            if ($action->status === 'failed') {
                DemoState::flash('Google gönderiyi kabul etmedi: '.$action->error, 'error');

                return;
            }
            DemoState::flash($action->status === 'scheduled' ? 'Gönderi zamanlandı.' : 'Gönderi Google’a gönderiliyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function cancelScheduled(int $actionId, ExternalWriteService $writes): void
    {
        try {
            $writes->cancelScheduled(auth()->user(), $this->writeAction($actionId));
            DemoState::flash('Zamanlanmış gönderi iptal edildi.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /* ---------------- Kategori ve hizmetler ---------------- */

    /** Fills the services list with the brand's approved services that are not on the profile yet. */
    public function fillFromOfferings(GbpAssistant $assistant, GbpDailyWorkspace $daily, GbpStandardInput $input): void
    {
        $asset = $this->asset()->loadMissing('brand');
        $resource = $daily->resource($asset);
        $onProfile = $resource !== null ? array_map(fn (string $s): string => SeoText::fold($s), $input->services((int) $resource->id)['labels']) : [];
        $missing = array_values(array_filter(array_column($assistant->offerings($asset->brand), 'name'),
            fn (string $name): bool => ! in_array(SeoText::fold($name), $onProfile, true)));
        if ($missing === []) {
            DemoState::flash('Markanın onaylı hizmetlerinin hepsi profilde var.', 'info');

            return;
        }
        $typed = array_map(fn (array $s): string => SeoText::fold($s['line']), GbpProfilePlanner::parse('', $this->wantServices)['services']);
        $missing = array_filter($missing, fn (string $name): bool => ! in_array(SeoText::fold($name), $typed, true));
        $this->wantServices = ltrim(rtrim($this->wantServices)."\n".implode("\n", $missing));
    }

    public function togglePeers(): void
    {
        $this->peerOpen = ! $this->peerOpen;
        $this->peerPick = [];
    }

    /** Picks all rows, those used by at least two profiles, or none. */
    public function pickPeers(string $mode, GbpPeerProfiles $peers): void
    {
        $found = $this->peerFound($peers);
        $rows = [...$found['categories'], ...$found['services']];
        $this->peerPick = $mode === 'none' ? [] : array_values(array_column(array_filter($rows, fn (array $r): bool => $mode === 'all' || $r['count'] >= 2), 'key'));
    }

    /** Adds the picked category and service names to the boxes (names only: AI writes this brand's descriptions). */
    public function addPeers(GbpPeerProfiles $peers, GbpDailyWorkspace $daily): void
    {
        $found = $this->peerFound($peers);
        $resource = $daily->resource($this->asset());
        $boxes = GbpPeerProfiles::boxes($found, $this->peerPick, $peers->profile($resource?->id !== null ? (int) $resource->id : null)['categories']);
        if ($boxes['categories'] === [] && $boxes['services'] === '') {
            DemoState::flash('Eklenecek satır seçin.', 'error');

            return;
        }
        $typedCategories = array_map(fn (string $c): string => SeoText::fold($c), GbpProfilePlanner::lines($this->wantCategories, 100));
        $newCategories = array_filter($boxes['categories'], fn (string $c): bool => ! in_array(SeoText::fold($c), $typedCategories, true));
        $this->wantCategories = trim(rtrim($this->wantCategories)."\n".implode("\n", $newCategories));
        $this->wantServices = trim(rtrim($this->wantServices)."\n\n".$boxes['services']);
        $this->peerOpen = false;
        $this->peerPick = [];
        DemoState::flash(count($boxes['categories']).' kategori ve seçilen hizmetler listeye eklendi. Açıklamalar diğer işletmelerden alınmadı; “AI ile hazırla” bu marka için yazar.', 'info');
    }

    /**
     * The profile's İşletme profilleri checks and what the desk sent to Google for it (shown on Genel Bakış).
     *
     * @return array{checks: array<string, array<string, mixed>>, score: int, history: list<array<string, mixed>>, asset_id: int, brand_id: int}|null
     */
    private function desk(int $assetId): ?array
    {
        $location = app(GbpDesk::class)->locations()->firstWhere('id', $assetId);
        if ($location === null) {
            return null;
        }
        $checks = app(DeskChecks::class);
        $row = $checks->rows(collect([$location]))['rows'][$assetId];

        return ['checks' => $row['checks'], 'score' => $row['score'], 'history' => $checks->history($assetId), 'asset_id' => $assetId, 'brand_id' => (int) $location->brand_id];
    }

    /** @return array{peers: int, brands: list<string>, categories: list<array<string, mixed>>, services: list<array<string, mixed>>} */
    private function peerFound(GbpPeerProfiles $peers): array
    {
        try {
            return $peers->collect($this->asset());
        } catch (RuntimeException $exception) {
            return ['peers' => 0, 'brands' => [], 'categories' => [], 'services' => [], 'error' => $exception->getMessage()];
        }
    }

    public function preparePlan(GbpAssistant $assistant): void
    {
        $this->queueAssistant($assistant, GbpAssistant::OP_PROFILE, GbpProfilePlanner::parse($this->wantCategories, $this->wantServices));
    }

    /** ADR-077: the Admin sends the chosen plan rows to the profile (additions only; undo from the list). */
    public function sendPlan(ExternalWriteService $writes, GbpProfilePlanner $planner): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        $plan = $planner->latest($this->asset());
        if ($plan === null || (int) $plan->id !== $this->planId) {
            DemoState::flash('Hazırlık değişti; listeyi kontrol edip yeniden gönderin.', 'error');
            $this->planId = null;

            return;
        }
        try {
            $writes->requestProfileUpdate(auth()->user(), $this->asset(), $plan, array_values(array_map('strval', $this->pickCategories)),
                array_values(array_map('intval', $this->pickServices)), $this->serviceText);
            $this->planId = null;
            DemoState::flash('Seçilenler İşletme Profili’ne gönderiliyor.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** "Google’dan yenile": reads the profile's categories and services again now. */
    public function refreshLive(GbpProfilePlanner $planner): void
    {
        $planner->live($this->asset(), true);
    }

    /** Closes the last "AI ile hazırla" message (an old error stays otherwise until the next run). */
    public function dismissPlanState(): void
    {
        Cache::forget(GbpAssistant::stateKey((int) $this->asset()->id, GbpAssistant::OP_PROFILE));
    }

    public function discardPlan(GbpProfilePlanner $planner, ProductionArchive $archive): void
    {
        $plan = $planner->latest($this->asset());
        if ($plan !== null) {
            $archive->mark($plan, AiProduction::STATUS_DISCARDED, auth()->user());
        }
        $this->planId = null;
        DemoState::flash('Hazırlık silindi.', 'info');
    }

    public function render(GbpOperatorWorkspace $workspace, GbpDailyWorkspace $daily, GbpScreen $screen, GbpSuggestions $suggestions, GbpAssistant $assistant): View
    {
        $this->normalize();
        $asset = $this->asset()->loadMissing('brand.customer', 'brand.sectorCategory');
        $data = $workspace->for($asset, 28);
        $resource = $daily->resource($asset);
        $resourceId = $resource?->id !== null ? (int) $resource->id : null;
        $assetId = (int) $asset->id;
        $plan = $this->tab === 'services' ? $this->syncPlan(app(GbpProfilePlanner::class)->latest($asset)) : null;
        $peerFound = $this->tab === 'services' && $this->peerOpen ? $this->peerFound(app(GbpPeerProfiles::class)) : null;

        return view('livewire.demo.gbp.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'tabs' => self::TABS,
            'data' => $data,
            'identity' => $data['identity'],
            'bound' => (bool) ($data['connection']['bound'] ?? false),
            'brand' => $asset->brand,
            'sectorName' => $asset->sector()?->name,
            'operational' => (bool) $asset->brand?->isOperational(),
            'flash' => DemoState::pullFlash(),
            'peerFound' => $peerFound,
            'desk' => $this->tab === 'overview' ? $this->desk($assetId) : null,
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP),
            'numbers' => $this->tab === 'overview' ? $screen->overview($asset, $resourceId, $this->dateRange()) : null,
            'openCount' => $asset->brand_id !== null ? $suggestions->open($asset)->count() : 0,
            'suggestions' => $this->tab === 'todo' ? $suggestions->open($asset) : collect(),
            'approved' => $this->tab === 'todo' && $asset->brand_id !== null ? $suggestions->approved($asset) : collect(),
            'servicesState' => $this->tab === 'todo' ? $assistant->state($assetId, GbpAssistant::OP_SERVICES) : null,
            'descriptionState' => $this->tab === 'todo' ? $assistant->state($assetId, GbpAssistant::OP_DESCRIPTION) : null,
            'reviewAccess' => $this->tab === 'reviews' && $resourceId !== null ? $daily->reviewAccess($asset) : null,
            'posts' => $this->tab === 'posts' ? $daily->posts($asset, $resourceId) : null,
            'pages' => $this->tab === 'posts' ? $assistant->shareablePages($asset) : [],
            'postDraft' => $this->tab === 'posts' ? $assistant->latestPost($asset) : null,
            'queue' => $this->tab === 'posts' && $asset->brand_id !== null ? app(GbpPostQueue::class)->summary($asset) : null,
            'calendar' => $this->tab === 'posts' && $asset->brand_id !== null ? $this->calendar($asset) : [],
            'postState' => $this->tab === 'posts' ? $assistant->state($assetId, GbpAssistant::OP_POST) : null,
            'plan' => $plan,
            'planState' => $this->tab === 'services' ? $assistant->state($assetId, GbpAssistant::OP_PROFILE) : null,
            'live' => $this->tab === 'services' && ($data['connection']['bound'] ?? false) ? app(GbpProfilePlanner::class)->live($asset) : null,
            'profileWrites' => $this->tab === 'services' ? ExternalWriteAction::query()->where('digital_asset_id', $assetId)
                ->where('action', ExternalWriteAction::ACTION_PROFILE_UPDATE)->latest('id')->limit(10)->get() : collect(),
            'analysis' => $this->tab === 'analysis' && $resourceId !== null ? $screen->analysis($resourceId, $this->dateRange()) : null,
            'range' => $this->dateRange(),
            'lastDay' => $screen->lastDay($resourceId)->toDateString(),
            'ranged' => in_array($this->tab, ['overview', 'analysis'], true),
        ]);
    }

    /** @param  array{page_id?: int}  $params */
    private function queueAssistant(GbpAssistant $assistant, string $operation, array $params = []): void
    {
        try {
            $assistant->queue($this->asset(), $operation, $params);
            DemoState::flash('AI çalışıyor; birkaç saniye sonra burada görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** A new plan preselects its new rows and loads its descriptions; the operator's choices stay while it is the same plan. */
    private function syncPlan(?AiProduction $plan): ?AiProduction
    {
        if ($plan === null || (int) $plan->id === $this->planId) {
            return $plan;
        }
        $this->planId = (int) $plan->id;
        $this->pickCategories = array_values(array_column(array_filter((array) data_get($plan->content, 'categories', []), fn (array $c): bool => $c['status'] === 'new'), 'id'));
        $this->pickServices = [];
        $this->serviceText = [];
        foreach ((array) data_get($plan->content, 'services', []) as $index => $service) {
            $this->serviceText[(int) $index] = (string) $service['description'];
            if ($service['status'] === 'new') {
                $this->pickServices[] = (int) $index;
            }
        }

        return $plan;
    }

    private function normalize(): void
    {
        $this->tab = self::LEGACY_TAB_MAP[$this->tab] ?? $this->tab;
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'overview';
        }
        $this->normalizeDateRange();
    }

    private function review(int $reviewId): GbpReview
    {
        $resourceIds = CoreAssetBinding::query()->where('digital_asset_id', $this->asset()->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id');

        return GbpReview::query()->whereIn('external_resource_id', $resourceIds)->findOrFail($reviewId);
    }

    private function writeAction(int $actionId): ExternalWriteAction
    {
        return ExternalWriteAction::query()->whereKey($actionId)->where('channel', ExternalWriteAction::CHANNEL_GBP)
            ->where('digital_asset_id', $this->asset()->id)->firstOrFail();
    }

    private function planned(int $id): GbpQueuedPost
    {
        return GbpQueuedPost::query()->whereKey($id)->where('digital_asset_id', $this->asset()->id)->firstOrFail();
    }

    /**
     * The next 30 days of the location's automatic posts, one row per day (empty days included), today first.
     *
     * @return list<array{day: string, label: string, week: string, today: bool, post: ?GbpQueuedPost}>
     */
    private function calendar(DigitalAsset $asset): array
    {
        $today = GbpPostQueue::today();
        $posts = GbpQueuedPost::query()->with('page:id,title,url,path')->where('digital_asset_id', $asset->id)
            ->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED, GbpQueuedPost::PUBLISHED, GbpQueuedPost::FAILED])
            ->whereBetween('publish_on', [$today->toDateString(), $today->addDays(GbpPostQueue::HORIZON_DAYS)->toDateString()])
            ->orderBy('publish_on')->orderByDesc('id')->get()->keyBy(fn (GbpQueuedPost $p): string => substr((string) $p->publish_on, 0, 10));
        $out = [];
        for ($i = 0; $i <= GbpPostQueue::HORIZON_DAYS; $i++) {
            $day = $today->addDays($i);
            $out[] = ['day' => $day->toDateString(), 'label' => $day->locale('tr')->translatedFormat('d M D'), 'week' => $day->startOfWeek()->locale('tr')->translatedFormat('d F').' haftası',
                'today' => $i === 0, 'post' => $posts->get($day->toDateString())];
        }

        return $out;
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->whereIn('type', ['google_business_profile', 'gbp'])->firstOrFail();
    }
}
