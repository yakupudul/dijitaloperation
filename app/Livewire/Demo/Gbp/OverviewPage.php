<?php

namespace App\Livewire\Demo\Gbp;

use App\Contracts\GbpOperatorWorkspace;
use App\Jobs\Gbp\SyncGbpSuggestionsJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\AiProduction;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Models\Suggestion;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Archive\ProductionArchive;
use App\Services\Async\AsyncOperationService;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpScreen;
use App\Services\Gbp\GbpSuggestions;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme Profili (Faz 7): Genel Bakış · Yapılacaklar · Yorumlar · Gönderiler · Analiz · Ayarlar for one bound location.
 * Numbers come from the collected gbp_* tables (GbpScreen); suggestions from the ONE suggestions table (GbpSuggestions);
 * AI work is queued (GbpAssistant, ReviewReplyDrafter). The only writes to Google are ADR-073 — a review reply and a
 * post (now or scheduled) — Admin-approved, recorded and undoable through ExternalWriteService. Profile fields
 * (categories, services, description) are changed by the operator on Google.
 */
#[Layout('operator.layouts.app')]
#[Title('İşletme Profili')]
class OverviewPage extends Component
{
    use ResolvesCanonicalOperatorAsset;

    public const array TABS = ['overview' => 'Genel Bakış', 'todo' => 'Yapılacaklar', 'reviews' => 'Yorumlar', 'posts' => 'Gönderiler', 'analysis' => 'Analiz', 'settings' => 'Ayarlar'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const array LEGACY_TAB_MAP = [
        'performance' => 'analysis', 'queries' => 'analysis', 'visibility' => 'analysis',
        'profile' => 'todo', 'health' => 'todo', 'advisor' => 'todo', 'setup' => 'settings', 'collect' => 'reviews',
        'insights' => 'overview', 'competitors' => 'overview', 'operations' => 'overview',
    ];

    /** @var list<int> */
    private const array DAY_OPTIONS = [28, 90, 180];

    #[Locked]
    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    /** Analiz window in days. */
    #[Url]
    public int $days = 28;

    #[Url]
    public bool $unanswered = false;

    /** Gönderiler form. @var array{body: string, url: string, action_type: string, when: string, publish_at: string} */
    public array $post = ['body' => '', 'url' => '', 'action_type' => 'LEARN_MORE', 'when' => 'now', 'publish_at' => ''];

    public bool $postFormOpen = false;

    /** Siteden paylaş: selected page id. */
    public string $sharePageId = '';

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

    public function setDays(int $days): void
    {
        $this->days = $days;
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

    public function approveSuggestion(int $id, GbpSuggestions $suggestions, AnalystDecisionStore $store): void
    {
        $store->markDone($suggestions->find($this->asset(), $id), auth()->user());
        DemoState::flash('Onaylandı.', 'success');
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

    public function render(GbpOperatorWorkspace $workspace, GbpDailyWorkspace $daily, GbpScreen $screen, GbpSuggestions $suggestions, GbpAssistant $assistant): View
    {
        $this->normalize();
        $asset = $this->asset()->loadMissing('brand.customer', 'brand.sectorCategory');
        $data = $workspace->for($asset, 28);
        $resource = $daily->resource($asset);
        $resourceId = $resource?->id !== null ? (int) $resource->id : null;
        $assetId = (int) $asset->id;
        $reviewList = $this->tab === 'reviews' && $resourceId !== null ? $daily->reviews($resourceId, '', $this->unanswered) : [];

        return view('livewire.demo.gbp.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'tabs' => self::TABS,
            'data' => $data,
            'identity' => $data['identity'],
            'bound' => (bool) ($data['connection']['bound'] ?? false),
            'brand' => $asset->brand,
            'operational' => (bool) $asset->brand?->isOperational(),
            'flash' => DemoState::pullFlash(),
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP),
            'numbers' => $this->tab === 'overview' ? $screen->overview($asset, $resourceId) : null,
            'openCount' => $asset->brand_id !== null ? $suggestions->open($asset)->count() : 0,
            'suggestions' => $this->tab === 'todo' ? $suggestions->open($asset) : collect(),
            'servicesState' => $this->tab === 'todo' ? $assistant->state($assetId, GbpAssistant::OP_SERVICES) : null,
            'descriptionState' => $this->tab === 'todo' ? $assistant->state($assetId, GbpAssistant::OP_DESCRIPTION) : null,
            'reviewAccess' => $this->tab === 'reviews' && $resourceId !== null ? $daily->reviewAccess($asset) : null,
            'reviewList' => $reviewList,
            'replyDrafts' => $this->replyDrafts($reviewList),
            'posts' => $this->tab === 'posts' ? $daily->posts($asset, $resourceId) : null,
            'pages' => $this->tab === 'posts' ? $assistant->shareablePages($asset) : [],
            'postDraft' => $this->tab === 'posts' ? $assistant->latestPost($asset) : null,
            'postState' => $this->tab === 'posts' ? $assistant->state($assetId, GbpAssistant::OP_POST) : null,
            'analysis' => $this->tab === 'analysis' && $resourceId !== null ? $screen->analysis($resourceId, $this->days) : null,
            'dayOptions' => self::DAY_OPTIONS,
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

    private function normalize(): void
    {
        $this->tab = self::LEGACY_TAB_MAP[$this->tab] ?? $this->tab;
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'overview';
        }
        if (! in_array($this->days, self::DAY_OPTIONS, true)) {
            $this->days = 28;
        }
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

    /**
     * @param  list<array<string, mixed>>  $reviews
     * @return array<int, array{text: ?string, state: ?string}>
     */
    private function replyDrafts(array $reviews): array
    {
        $ids = collect($reviews)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $drafts = AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->where('subject_type', 'GbpReview')->whereIn('subject_id', $ids)
            ->where('status', '!=', AiProduction::STATUS_DISCARDED)->orderBy('version')->get()->keyBy('subject_id');
        $drafter = app(ReviewReplyDrafter::class);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['text' => $drafts->has($id) ? (string) data_get($drafts[$id]->content, 'reply') : null, 'state' => $drafter->state($id)];
        }

        return $out;
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->whereIn('type', ['google_business_profile', 'gbp'])->firstOrFail();
    }
}
