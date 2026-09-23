<?php

namespace App\Livewire\Operator\Advisor;

use App\Enums\AdvisorCategory;
use App\Enums\AdvisorItemStatus;
use App\Jobs\DraftGoogleAdsAdCopyJob;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One list, two homes: /ads-advisor (all Google Ads accounts) and the Google Ads account "Danışman" tab
 * (assetId set). Rule items with evidence, paste-ready lists and an on-click AI ad copy draft.
 */
final class AdvisorPanel extends Component
{
    #[Locked]
    public ?int $assetId = null;

    #[Url(as: 'adv_customer')]
    public string $customerFilter = '';

    #[Url(as: 'adv_asset')]
    public string $assetFilter = '';

    #[Url(as: 'adv_category')]
    public string $categoryFilter = '';

    #[Url(as: 'adv_status')]
    public string $statusFilter = 'open';

    public ?int $expandedId = null;

    public string $message = '';

    public string $messageTone = 'success';

    public function mount(?int $assetId = null): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->assetId = $assetId;
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function markDone(int $id): void
    {
        $this->resolve($id, AdvisorItemStatus::Done, 'Yapıldı olarak işaretlendi. Sonraki çalıştırmalar bu öneriyi tekrar açmaz; etkisi ölçüm için saklandı.');
    }

    public function skip(int $id): void
    {
        $this->resolve($id, AdvisorItemStatus::Skipped, 'Öneri atlandı; tekrar gösterilmez.');
    }

    public function reopen(int $id): void
    {
        $this->item($id)->forceFill(['status' => AdvisorItemStatus::Open->value, 'resolved_at' => null, 'resolved_by' => null])->save();
        $this->flash('Öneri yeniden açıldı.');
    }

    /** Operator-approved AI call: queue an ad copy draft for a weak-ad-strength item. */
    public function requestDraft(int $id): void
    {
        $item = $this->item($id);
        if ($item->rule_id !== 'weak-ad-strength') {
            return;
        }
        if ($item->draft_status === 'queued') {
            return;
        }
        $item->forceFill(['draft_status' => 'queued', 'draft' => null])->save();
        dispatch(new DraftGoogleAdsAdCopyJob($item->id))
            ->onConnection((string) config('moxdop-advisor.queue_connection', config('queue.default')))
            ->onQueue((string) config('moxdop-advisor.queue', 'default'));
        $this->expandedId = $id;
        $this->flash('Reklam metni taslağı hazırlanıyor (1 AI çağrısı). Hazır olunca burada görünür.');
    }

    public function refreshAsset(int $assetId, AdvisorPlanRunner $runner): void
    {
        $asset = DigitalAsset::query()->findOrFail($assetId);
        try {
            $plan = $runner->queue($asset, auth()->user(), 'manual');
        } catch (ValidationException $exception) {
            $this->flash(implode(' ', $exception->validator->errors()->all()), 'error');

            return;
        }
        $this->flash(sprintf('%s için danışman #%d çalışıyor. Sonuç birkaç saniye içinde gelir.', $asset->name, $plan->version));
    }

    public function refreshAll(AdvisorPlanRunner $runner): void
    {
        $plans = $runner->queueAll(auth()->user(), onlyConnected: true, trigger: 'bulk');
        $this->flash(sprintf('%d reklam hesabı için danışman çalışıyor. Durum aşağıda canlı güncellenir.', $plans->count()));
    }

    public function clearFilters(): void
    {
        $this->customerFilter = '';
        $this->assetFilter = '';
        $this->categoryFilter = '';
        $this->statusFilter = 'open';
    }

    public function render(): View
    {
        $items = $this->query()->limit(100)->get();
        $open = $this->query(ignoreCategory: true, ignoreStatus: true)->open()->get();
        $counts = $open->countBy(fn (AdvisorItem $item): string => $item->category->value)->all();
        $board = $this->board($open);
        $asset = $this->assetId !== null ? DigitalAsset::query()->with('brand')->find($this->assetId) : null;
        $pending = $board->whereIn('plan_status', [AdvisorPlan::STATUS_QUEUED, AdvisorPlan::STATUS_RUNNING])->count();
        $draftsPending = $items->where('draft_status', 'queued')->count();
        $currency = $open->pluck('currency')->filter()->unique();

        return view('livewire.operator.advisor.advisor-panel', [
            'items' => $items,
            'counts' => $counts,
            'board' => $board,
            'asset' => $asset,
            'polling' => $pending > 0 || $draftsPending > 0,
            'plansPending' => $pending,
            'kpis' => [
                'open' => $open->count(),
                'urgent' => $open->whereIn('severity', ['critical', 'high'])->count(),
                'waste' => $currency->count() <= 1 ? (float) $open->where('category', AdvisorCategory::Waste)->sum('impact_amount') : null,
                'currency' => $currency->first(),
                'accounts' => $board->where('bound', true)->count(),
            ],
            'categories' => AdvisorCategory::cases(),
            'customers' => $this->assetId === null ? Customer::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    /** @return Builder<AdvisorItem> */
    private function query(bool $ignoreCategory = false, bool $ignoreStatus = false): Builder
    {
        $query = AdvisorItem::query()->with(['brand', 'digitalAsset'])->where('channel', AdvisorPlan::CHANNEL_GOOGLE_ADS);
        if ($this->assetId !== null) {
            $query->where('digital_asset_id', $this->assetId);
        } else {
            if (ctype_digit($this->customerFilter)) {
                $query->where('customer_id', (int) $this->customerFilter);
            }
            if (ctype_digit($this->assetFilter)) {
                $query->where('digital_asset_id', (int) $this->assetFilter);
            }
        }
        if (! $ignoreCategory && AdvisorCategory::tryFrom($this->categoryFilter) !== null) {
            $query->where('category', $this->categoryFilter);
        }
        if (! $ignoreStatus) {
            if ($this->statusFilter === 'all') {
                // no filter
            } elseif (AdvisorItemStatus::tryFrom($this->statusFilter) !== null) {
                $query->where('status', $this->statusFilter);
            } else {
                $query->where('status', AdvisorItemStatus::Open->value);
            }
        }

        return $query->orderByDesc('priority_score')->orderBy('id');
    }

    /**
     * Every active Google Ads account in scope with its latest run state.
     *
     * @param  Collection<int, AdvisorItem>  $open
     * @return Collection<int, array<string, mixed>>
     */
    private function board(Collection $open): Collection
    {
        $assets = DigitalAsset::query()
            ->with('brand')
            ->where('type', 'google_ads')
            ->where('status', 'active')
            ->when($this->assetId !== null, fn (Builder $query) => $query->whereKey($this->assetId))
            ->when($this->assetId === null && ctype_digit($this->customerFilter), fn (Builder $query) => $query->whereHas('brand', fn (Builder $brand) => $brand->where('customer_id', (int) $this->customerFilter)))
            ->orderBy('name')
            ->get();
        $plans = AdvisorPlan::query()->whereIn('digital_asset_id', $assets->pluck('id'))->where('channel', AdvisorPlan::CHANNEL_GOOGLE_ADS)->orderByDesc('id')->get();
        $latest = $plans->unique('digital_asset_id')->keyBy('digital_asset_id');
        $completed = $plans->where('status', AdvisorPlan::STATUS_COMPLETED)->unique('digital_asset_id')->keyBy('digital_asset_id');
        $byAsset = $open->groupBy('digital_asset_id');

        return $assets->map(function (DigitalAsset $asset) use ($latest, $completed, $byAsset): array {
            $plan = $latest->get($asset->id);
            $done = $completed->get($asset->id);
            $rows = $byAsset->get($asset->id, collect());

            return [
                'id' => $asset->id,
                'name' => $asset->name,
                'brand' => $asset->brand?->name,
                'plan_status' => $plan?->status,
                'plan_error' => $plan?->status === AdvisorPlan::STATUS_FAILED ? $plan->error_summary : null,
                'last_run_at' => $done?->completed_at,
                'summary' => $done?->summary_text,
                'bound' => data_get($done?->input_summary, 'bound'),
                'account' => data_get($done?->input_summary, 'account'),
                'currency' => data_get($done?->input_summary, 'currency'),
                'sources' => data_get($done?->input_summary, 'sources'),
                'open' => $rows->count(),
                'urgent' => $rows->whereIn('severity', ['critical', 'high'])->count(),
                'waste' => (float) $rows->where('category', AdvisorCategory::Waste)->sum('impact_amount'),
            ];
        })->sortByDesc(fn (array $row): float => $row['urgent'] * 1e9 + $row['waste'])->values();
    }

    private function item(int $id): AdvisorItem
    {
        $query = AdvisorItem::query()->whereKey($id);
        if ($this->assetId !== null) {
            $query->where('digital_asset_id', $this->assetId);
        }

        return $query->firstOrFail();
    }

    private function resolve(int $id, AdvisorItemStatus $status, string $message): void
    {
        $this->item($id)->forceFill(['status' => $status->value, 'resolved_at' => now(), 'resolved_by' => auth()->id()])->save();
        $this->flash($message);
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->messageTone = $tone;
    }
}
