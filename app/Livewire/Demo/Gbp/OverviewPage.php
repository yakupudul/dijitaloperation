<?php

namespace App\Livewire\Demo\Gbp;

use App\Contracts\GbpOperatorWorkspace;
use App\Livewire\Concerns\WithAiInsights;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\AdvisorItem;
use App\Models\AiProduction;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\ContentCalendarItem;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Services\Archive\ProductionArchive;
use App\Services\Async\AsyncOperationService;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpPostDrafter;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Services\Intel\ReviewIntelService;
use App\Support\Demo\DemoState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Google Business Profile asset page: the operator's daily workspace for one location. Data comes from the bound
 * location's collected provider tables (OperatorGbpWorkspace, GbpDailyWorkspace) and the content calendar.
 * The only writes to Google are the ADR-073 ones — a review reply and a post — both Admin-approved, logged and undoable
 * through ExternalWriteService. Profile fields (hours, categories…) are edited by the operator on Google.
 */
#[Layout('operator.layouts.app')]
#[Title('İşletme Profili')]
class OverviewPage extends Component
{
    use ResolvesCanonicalOperatorAsset;
    use WithAiInsights;

    #[Locked]
    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    /** Performance window in days. */
    #[Url]
    public int $days = 28;

    /** Yorumlar filter: '' | low | mid | high (GbpDailyWorkspace::RATING_FILTERS). */
    #[Url]
    public string $rating = '';

    #[Url]
    public bool $unanswered = false;

    /** Gönderiler form (a content calendar item of this profile). @var array{title: string, body: string, url: string, action_type: string, scheduled_for: string} */
    public array $post = ['title' => '', 'body' => '', 'url' => '', 'action_type' => 'LEARN_MORE', 'scheduled_for' => ''];

    /** null = form closed, 0 = new post, otherwise the calendar item being edited. */
    public ?int $editingPostId = null;

    /** Optional subject for the AI post draft. */
    public string $postTopic = '';

    /** @var list<string> */
    public array $allowedTabs = ['overview', 'reviews', 'posts', 'performance', 'profile', 'collect', 'advisor'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const LEGACY_TAB_MAP = [
        'queries' => 'performance',
        'insights' => 'overview',
        'visibility' => 'performance',
        'competitors' => 'overview',
        'operations' => 'advisor',
        'setup' => 'profile',
        'health' => 'profile',
    ];

    /** @var list<int> */
    private const DAY_OPTIONS = [28, 90, 180];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['google_business_profile', 'gbp']);
        $this->normalizeTab();
        $this->normalizeDays();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    public function setDays(int $days): void
    {
        $this->days = $days;
        $this->normalizeDays();
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->asset(), auth()->user(), [
            'trigger' => 'operator.gbp.refresh',
        ]);

        DemoState::flash(
            (string) ($result['message'] ?? __('operator_runtime.sources.collect_failed')),
            ($result['ok'] ?? false) ? 'success' : 'info',
        );
    }

    protected function normalizeTab(): void
    {
        $this->tab = self::LEGACY_TAB_MAP[$this->tab] ?? $this->tab;
        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
        if (! isset(GbpDailyWorkspace::RATING_FILTERS[$this->rating])) {
            $this->rating = '';
        }
    }

    protected function normalizeDays(): void
    {
        if (! in_array($this->days, self::DAY_OPTIONS, true)) {
            $this->days = 28;
        }
    }

    /** Faz 14: AI reply draft for one of this location's reviews (on click; publishing is a separate Admin step). */
    public function draftReply(int $reviewId, ReviewReplyDrafter $drafter): void
    {
        try {
            $drafter->queue($this->review($reviewId));
            DemoState::flash('Yanıt taslağı hazırlanıyor; birkaç saniye sonra yorumun altında görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** ADR-073: the Admin-approved reply goes to Google (undo from the list or the write log). */
    public function publishReply(int $reviewId, string $text, ExternalWriteService $writes): void
    {
        try {
            $writes->requestReviewReply(auth()->user(), $this->review($reviewId), $text);
            DemoState::flash('Yanıt Google’a gönderiliyor; birkaç saniye içinde yayında olur.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** ADR-073 undo of a reply or a post of this profile (Admin). */
    public function undoWrite(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->whereKey($actionId)->where('channel', ExternalWriteAction::CHANNEL_GBP)
            ->where('digital_asset_id', $this->asset()->id)->firstOrFail();
        try {
            $writes->requestUndo(auth()->user(), $action);
            DemoState::flash('Geri alınıyor; birkaç saniye içinde Google’dan kaldırılır.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function startPost(): void
    {
        $this->editingPostId = 0;
        $this->post = ['title' => '', 'body' => '', 'url' => '', 'action_type' => 'LEARN_MORE',
            'scheduled_for' => now('Europe/Istanbul')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')];
        $this->resetValidation();
    }

    public function editPost(int $id): void
    {
        $item = $this->postItem($id);
        abort_unless($item->write_action_id === null && in_array($item->status, ['draft', 'failed', 'approved'], true), 422);
        $this->editingPostId = $item->id;
        $this->post = ['title' => $item->title, 'body' => (string) $item->body, 'url' => (string) $item->url, 'action_type' => (string) ($item->action_type ?: 'LEARN_MORE'),
            'scheduled_for' => $item->scheduled_for->timezone('Europe/Istanbul')->format('Y-m-d\TH:i')];
        $this->resetValidation();
    }

    public function cancelPost(): void
    {
        $this->editingPostId = null;
        $this->resetValidation();
    }

    /**
     * Saves the form to the content calendar. `draft` waits for approval; `approve` (Admin) publishes at the chosen
     * time (ContentCalendarPublisher); `publish` (Admin) publishes now.
     */
    public function savePost(string $mode, ExternalWriteService $writes): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $mode = in_array($mode, ['draft', 'approve', 'publish'], true) ? $mode : 'draft';
        if ($mode !== 'draft') {
            abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        }
        $asset = $this->asset();
        if ($asset->brand_id === null) {
            DemoState::flash('Bu profil bir markaya bağlı değil; gönderi planlamak için önce markaya bağlayın.', 'error');

            return;
        }
        $data = $this->validate([
            'post.title' => ['required', 'string', 'max:200'],
            'post.body' => ['nullable', 'string', 'max:1250'],
            'post.url' => ['nullable', 'url', 'max:500'],
            'post.action_type' => ['nullable', Rule::in(['LEARN_MORE', 'BOOK', 'CALL', 'ORDER', 'SIGN_UP'])],
            'post.scheduled_for' => [$mode === 'publish' ? 'nullable' : 'required', 'date'],
        ], [], ['post.title' => 'başlık', 'post.body' => 'metin', 'post.url' => 'bağlantı', 'post.scheduled_for' => 'yayın zamanı'])['post'];
        $values = [
            'brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id, 'channel' => 'gbp_post',
            'title' => $data['title'], 'body' => ($data['body'] ?? '') ?: null, 'url' => ($data['url'] ?? '') ?: null, 'action_type' => ($data['action_type'] ?? '') ?: 'LEARN_MORE',
            'scheduled_for' => $mode === 'publish' || blank($data['scheduled_for'] ?? null) ? now() : CarbonImmutable::parse($data['scheduled_for'], 'Europe/Istanbul')->utc(),
            'status' => $mode === 'draft' ? 'draft' : 'approved', 'approved_by' => $mode === 'draft' ? null : auth()->id(), 'error' => null,
        ];
        if ($this->editingPostId) {
            $item = $this->postItem($this->editingPostId);
            abort_unless($item->write_action_id === null && $item->status !== 'published', 422);
            $item->forceFill($values)->save();
        } else {
            $item = ContentCalendarItem::query()->create($values + ['created_by' => auth()->id()]);
        }
        $this->editingPostId = null;
        if ($mode === 'publish') {
            $this->publishItem($item, $writes);

            return;
        }
        DemoState::flash($mode === 'draft' ? 'Gönderi takvime kaydedildi; Admin onayından sonra zamanında yayınlanır.' : 'Onaylandı; gönderi seçilen zamanda Google’da yayınlanır.');
    }

    public function approvePost(int $id): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        $item = $this->postItem($id);
        abort_unless($item->write_action_id === null && in_array($item->status, ['draft', 'failed'], true), 422);
        $item->forceFill(['status' => 'approved', 'approved_by' => auth()->id(), 'error' => null,
            'scheduled_for' => $item->scheduled_for->isPast() ? now() : $item->scheduled_for])->save();
        DemoState::flash('Onaylandı; gönderi zamanında Google’da yayınlanır.');
    }

    public function publishPostNow(int $id, ExternalWriteService $writes): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        $item = $this->postItem($id);
        abort_unless($item->write_action_id === null && in_array($item->status, ['draft', 'failed', 'approved'], true), 422);
        $item->forceFill(['status' => 'approved', 'approved_by' => auth()->id(), 'error' => null, 'scheduled_for' => now()])->save();
        $this->publishItem($item, $writes);
    }

    public function skipPost(int $id): void
    {
        $item = $this->postItem($id);
        abort_unless($item->write_action_id === null && $item->status !== 'published', 422);
        $item->forceFill(['status' => 'skipped'])->save();
        DemoState::flash('Gönderi takvimden çıkarıldı.');
    }

    public function draftPostWithAi(GbpPostDrafter $drafter): void
    {
        try {
            $drafter->queue($this->asset(), $this->postTopic);
            DemoState::flash('Gönderi taslağı hazırlanıyor; birkaç saniye sonra burada görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** Loads an AI draft into the form (the operator edits it before saving). */
    public function useAiDraft(int $productionId, ProductionArchive $archive): void
    {
        $draft = AiProduction::query()->whereKey($productionId)->where('kind', GbpPostDrafter::KIND)
            ->where('subject_type', 'DigitalAsset')->where('subject_id', $this->asset()->id)->firstOrFail();
        $this->startPost();
        $this->post['title'] = mb_substr((string) data_get($draft->content, 'title'), 0, 200);
        $this->post['body'] = mb_substr((string) data_get($draft->content, 'body'), 0, 1250);
        $this->post['action_type'] = (string) (data_get($draft->content, 'action_type') ?: 'LEARN_MORE');
        $archive->mark($draft, AiProduction::STATUS_USED, auth()->user());
    }

    private function publishItem(ContentCalendarItem $item, ExternalWriteService $writes): void
    {
        try {
            $action = $writes->requestLocalPost(auth()->user(), $this->asset(), [
                'summary' => trim($item->title."\n\n".$item->body), 'url' => $item->url, 'action_type' => $item->action_type, 'calendar_id' => $item->id,
            ]);
            ContentCalendarItem::query()->whereKey($item->id)->update(['write_action_id' => $action->id]);
            $action->refresh();
            if ($action->status === 'failed') {
                ContentCalendarItem::query()->whereKey($item->id)->update(['status' => 'failed', 'error' => mb_substr((string) $action->error, 0, 300)]);
                DemoState::flash('Google gönderiyi kabul etmedi: '.$action->error, 'error');

                return;
            }
            DemoState::flash('Gönderi Google’a gönderiliyor; birkaç saniye içinde yayında olur. Gerekirse listeden geri alabilirsiniz.', 'info');
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->first();
            $item->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 300)])->save();
            DemoState::flash($message, 'error');
        }
    }

    private function postItem(int $id): ContentCalendarItem
    {
        return ContentCalendarItem::query()->where('digital_asset_id', $this->asset()->id)->where('channel', 'gbp_post')->findOrFail($id);
    }

    private function review(int $reviewId): GbpReview
    {
        $resourceIds = CoreAssetBinding::query()->where('digital_asset_id', $this->asset()->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id');

        return GbpReview::query()->whereIn('external_resource_id', $resourceIds)->findOrFail($reviewId);
    }

    protected function insightSubject(string $kind, int $subjectId): ?Model
    {
        return $kind === 'reviews.themes' && $subjectId === (int) $this->asset()->brand_id ? Brand::query()->find($subjectId) : null;
    }

    public function render(GbpOperatorWorkspace $workspace, GbpDailyWorkspace $daily, GbpPostDrafter $postDrafter): View
    {
        $this->normalizeTab();
        $this->normalizeDays();

        $asset = $this->asset()->loadMissing('brand');
        $data = $workspace->for($asset, $this->days);
        $topAdvice = AdvisorItem::query()->open()
            ->where('digital_asset_id', $asset->id)
            ->orderByDesc('priority_score')
            ->limit(3)
            ->get();
        $resource = $daily->resource($asset);
        $resourceId = $resource?->id !== null ? (int) $resource->id : null;
        $reviewLink = $resource !== null ? $daily->reviewLink($daily->placeId($resource)) : null;
        $reviewList = $this->tab === 'reviews' && $resourceId !== null ? $daily->reviews($resourceId, $this->rating, $this->unanswered) : [];

        return view('livewire.demo.gbp.overview', [
            'themesInsight' => $this->tab === 'reviews' && $asset->brand !== null ? $this->insightView('reviews.themes', $asset->brand) : null,
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'identity' => $data['identity'],
            'dayOptions' => self::DAY_OPTIONS,
            'topAdvice' => $topAdvice,
            'flash' => DemoState::pullFlash(),
            'reviewAccess' => $resourceId !== null ? $daily->reviewAccess($asset) : null,
            'reviewList' => $reviewList,
            'replyDrafts' => $this->replyDrafts($reviewList),
            'replyCost' => $this->tab === 'reviews' ? app(ReviewReplyDrafter::class)->estimate() : null,
            'badReviewAlert' => $this->tab === 'reviews' && AssetAlert::query()->active()->where('digital_asset_id', $asset->id)->where('kind', 'bad_review_unanswered')->exists(),
            'competitors' => $this->tab === 'reviews' && $asset->brand !== null
                ? collect(app(ReviewIntelService::class)->comparison($asset->brand))->filter(fn (array $row): bool => $row['rating'] !== null)->take(6)->values()->all() : [],
            'posts' => in_array($this->tab, ['posts', 'overview'], true) ? $daily->posts($asset, $resourceId) : null,
            'postDraft' => $this->tab === 'posts' ? $postDrafter->latest($asset) : null,
            'postDraftState' => $this->tab === 'posts' ? $postDrafter->state((int) $asset->id) : null,
            'postCost' => $this->tab === 'posts' ? $postDrafter->estimate() : null,
            'health' => $this->tab === 'profile' && $resourceId !== null ? $daily->health($resourceId, $asset) : null,
            'managerUrl' => GbpDailyWorkspace::MANAGER_URL,
            'reviewLink' => $reviewLink,
            'reviewQr' => $this->tab === 'collect' && $reviewLink !== null ? $daily->qrSvg($reviewLink) : null,
            'canPublishReplies' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP),
        ]);
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
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['text' => $drafts->has($id) ? (string) data_get($drafts[$id]->content, 'reply') : null, 'state' => app(ReviewReplyDrafter::class)->state($id)];
        }

        return $out;
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->whereIn('type', ['google_business_profile', 'gbp'])
            ->firstOrFail();
    }
}
