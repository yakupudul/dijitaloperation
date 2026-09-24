<?php

namespace App\Livewire\Demo\Gbp;

use App\Contracts\GbpOperatorWorkspace;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\AdvisorItem;
use App\Models\AiProduction;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Services\Async\AsyncOperationService;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Google Business Profile asset page. Everything shown comes from the bound location's collected provider
 * tables (see OperatorGbpWorkspace); tabs without a data source (local rank grid, competitors) are not shown.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Business Profile')]
class OverviewPage extends Component
{
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    /** Performance window in days. */
    #[Url]
    public int $days = 28;

    /** @var list<string> */
    public array $allowedTabs = ['overview', 'performance', 'reviews', 'profile', 'advisor'];

    /** @var array<string, string> Retired tab keys kept working for old links. */
    private const LEGACY_TAB_MAP = [
        'queries' => 'performance',
        'insights' => 'overview',
        'visibility' => 'performance',
        'competitors' => 'overview',
        'operations' => 'advisor',
        'setup' => 'profile',
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
    }

    protected function normalizeDays(): void
    {
        if (! in_array($this->days, self::DAY_OPTIONS, true)) {
            $this->days = 28;
        }
    }

    /** Faz 14: AI reply draft for one of this location's reviews (on click; nothing is posted to Google). */
    public function draftReply(int $reviewId, ReviewReplyDrafter $drafter): void
    {
        $resourceIds = CoreAssetBinding::query()->where('digital_asset_id', $this->asset()->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id');
        $review = GbpReview::query()->whereIn('external_resource_id', $resourceIds)->findOrFail($reviewId);
        try {
            $drafter->queue($review);
            DemoState::flash('Yanıt taslağı hazırlanıyor; birkaç saniye sonra yorumun altında görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function render(GbpOperatorWorkspace $workspace): View
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

        return view('livewire.demo.gbp.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'identity' => $data['identity'],
            'dayOptions' => self::DAY_OPTIONS,
            'topAdvice' => $topAdvice,
            'flash' => DemoState::pullFlash(),
            'replyDrafts' => $this->tab === 'reviews' ? $this->replyDrafts($data) : [],
            'replyCost' => $this->tab === 'reviews' ? app(ReviewReplyDrafter::class)->estimate() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{text: ?string, state: ?string}>
     */
    private function replyDrafts(array $data): array
    {
        $ids = collect((array) data_get($data, 'reviews_live.latest', []))->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();
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
