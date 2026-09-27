<?php

namespace App\Livewire\Operator\Advisor;

use App\Enums\AdvisorCategory;
use App\Enums\AdvisorItemStatus;
use App\Enums\CustomerStatus;
use App\Livewire\Concerns\WithAiInsights;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Services\Advisor\AdvisorChannels;
use App\Services\Advisor\AdvisorItemActions;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Services\Advisor\GoogleAds\GoogleAdsEditorExport;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Support\Permissions;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One list, several homes: /ads-advisor (every ad account, all channels) and each ad account's "Danışman" tab
 * (assetId set). Rule items with evidence, paste-ready lists and an on-click AI copy draft.
 */
final class AdvisorPanel extends Component
{
    use WithAiInsights;

    #[Locked]
    public ?int $assetId = null;

    #[Url(as: 'adv_customer')]
    public string $customerFilter = '';

    #[Url(as: 'adv_channel')]
    public string $channelFilter = '';

    #[Url(as: 'adv_asset')]
    public string $assetFilter = '';

    #[Url(as: 'adv_category')]
    public string $categoryFilter = '';

    #[Url(as: 'adv_status')]
    public string $statusFilter = 'open';

    public ?int $expandedId = null;

    public string $message = '';

    /** @var array<int|string, string> advisor item id => editable negative list before sending (ADR-064) */
    public array $writeLines = [];

    public string $messageTone = 'success';

    public function mount(?int $assetId = null): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->assetId = $assetId;
    }

    /** @var list<int> open items selected for a bulk action */
    public array $bulkIds = [];

    /** Bulk "Yapıldı": marks the selected open items done and re-checks each asset once (rules only). */
    public function bulkDone(): void
    {
        $items = $this->selectedOpenItems();
        foreach ($items as $item) {
            $item->forceFill(['status' => AdvisorItemStatus::Done->value, 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'verification' => null, 'verified_at' => null, 'snoozed_until' => null])->save();
        }
        foreach ($items->unique('digital_asset_id') as $item) {
            $this->verifyNow($item->id);
        }
        $this->bulkIds = [];
        $this->flash($items->count().' öneri yapıldı olarak işaretlendi; kayıtlı veriyle kontrol ediliyor.');
    }

    public function bulkSkip(): void
    {
        $items = $this->selectedOpenItems();
        $items->each(fn (AdvisorItem $item) => $item->forceFill(['status' => AdvisorItemStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'snoozed_until' => null])->save());
        $this->bulkIds = [];
        $this->flash($items->count().' öneri atlandı.');
    }

    public function bulkSnooze(int $days = 30): void
    {
        $days = max(1, min(180, $days));
        $items = $this->selectedOpenItems();
        $items->each(fn (AdvisorItem $item) => $item->forceFill(['status' => AdvisorItemStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'snoozed_until' => now()->addDays($days)])->save());
        $this->bulkIds = [];
        $this->flash($items->count().' öneri '.$days.' gün ertelendi.');
    }

    /** @var list<array{id: int, title: string, reason: string}> items of the last Editor export that stay manual */
    public array $manualItems = [];

    /**
     * Value loop: the selected Google Ads items as a Google Ads Editor import file. Nothing is sent to Google Ads;
     * exported items are marked "dışa aktarıldı" until the operator imports the file and marks them done.
     */
    public function exportEditor(GoogleAdsEditorExport $export): ?StreamedResponse
    {
        $items = $this->selectedOpenItems();
        $result = $export->build($items);
        $this->manualItems = $result['manual'];
        if ($result['rows'] === []) {
            $this->flash('Seçili önerilerden Editor dosyasına çevrilebilen satır çıkmadı; aşağıdaki öneriler elle yapılacak.', 'error');

            return null;
        }
        AdvisorItem::query()->whereIn('id', $result['exported'])->update(['exported_at' => now(), 'exported_by' => auth()->id(), 'updated_at' => now()]);
        $this->bulkIds = [];
        $this->flash(sprintf('%d öneriden %d satırlık Google Ads Editor dosyası hazırlandı. Editor\'da Hesap → İçe aktar → Dosyadan ile yükle, değişiklikleri gözden geçirip gönder; sonra öneriyi "Yapıldı" olarak işaretle.', count($result['exported']), count($result['rows'])));
        $body = $export->file($result['rows']);

        return response()->streamDownload(static function () use ($body): void {
            echo $body;
        }, 'moxdop-google-ads-editor-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-16LE']);
    }

    /** @return Collection<int, AdvisorItem> */
    private function selectedOpenItems(): Collection
    {
        return AdvisorItem::query()->whereIn('id', array_map('intval', $this->bulkIds))->where('status', AdvisorItemStatus::Open->value)
            ->when($this->assetId !== null, fn ($q) => $q->where('digital_asset_id', $this->assetId))->get();
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function markDone(int $id): void
    {
        $this->resolve($id, AdvisorItemStatus::Done, 'Yapıldı olarak işaretlendi. Şimdi kayıtlı veriyle kontrol ediliyor (AI yok): sorun görünmezse "Doğrulandı"; veri günlük toplandığı için ilk gün "Hâlâ görünüyor" olabilir, 7 günden sonra hâlâ görünürse öneri yeniden açılır. Etkisi 28 ve 56 gün sonra ölçülür.');
        $this->verifyNow($id);
    }

    /** Faz 7: re-run the same channel's rules for this asset right away (rules only, no AI) to verify the item. */
    private function verifyNow(int $id): void
    {
        app(AdvisorItemActions::class)->verify($this->item($id), auth()->user());
    }

    /** Faz 7: hide an item for N days; it comes back if the problem is still there. */
    public function snooze(int $id, int $days = 30): void
    {
        $days = max(1, min(180, $days));
        $this->item($id)->forceFill(['status' => AdvisorItemStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'snoozed_until' => now()->addDays($days)])->save();
        $this->flash($days.' gün ertelendi; sorun o zaman hâlâ varsa öneri geri gelir.');
    }

    public function skip(int $id): void
    {
        $this->resolve($id, AdvisorItemStatus::Skipped, 'Öneri atlandı; tekrar gösterilmez.');
    }

    public function reopen(int $id): void
    {
        $this->item($id)->forceFill(['status' => AdvisorItemStatus::Open->value, 'resolved_at' => null, 'resolved_by' => null, 'exported_at' => null, 'exported_by' => null])->save();
        $this->flash('Öneri yeniden açıldı.');
    }

    /** Operator-approved AI call: queue a copy draft for an item whose rule offers one. */
    public function requestDraft(int $id): void
    {
        $message = app(AdvisorItemActions::class)->requestDraft($this->item($id));
        if ($message === null) {
            return;
        }
        $this->expandedId = $id;
        $this->flash($message);
    }

    /** ADR-064: open the editable list for an Admin before sending it to Google Ads. */
    public function prepareNegativeWrite(int $id): void
    {
        $this->writeLines[$id] = (string) $this->item($id)->copy_text;
        $this->expandedId = $id;
    }

    public function cancelNegativeWrite(int $id): void
    {
        unset($this->writeLines[$id]);
    }

    public function applyNegativeList(int $id, ExternalWriteService $writes): void
    {
        try {
            $action = $writes->requestNegativeList(auth()->user(), $this->item($id), (string) ($this->writeLines[$id] ?? ''));
        } catch (ValidationException $exception) {
            $this->flash(implode(' ', $exception->validator->errors()->all()), 'error');

            return;
        }
        unset($this->writeLines[$id]);
        $this->flash(sprintf('%d terim Google Ads\'e gönderiliyor ("%s" listesi). Sonuç birkaç saniye içinde burada görünür.', count($action->request_payload['keywords']), config('moxdop-external-writes.google_ads.shared_set_name')));
    }

    public function undoWrite(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->whereNotNull('advisor_item_id')->findOrFail($actionId);
        $this->item((int) $action->advisor_item_id);
        try {
            $writes->requestUndo(auth()->user(), $action);
        } catch (ValidationException $exception) {
            $this->flash(implode(' ', $exception->validator->errors()->all()), 'error');

            return;
        }
        $this->flash('Geri alınıyor: bu gönderimle eklenen terimler listeden çıkarılacak.');
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
        $this->channelFilter = '';
        $this->assetFilter = '';
        $this->categoryFilter = '';
        $this->statusFilter = 'open';
    }

    public function render(AdvisorChannels $channels): View
    {
        $items = $this->query()->limit(100)->get();
        $open = $this->query(ignoreCategory: true, ignoreStatus: true)->open()->get();
        $counts = $open->countBy(fn (AdvisorItem $item): string => $item->category->value)->all();
        $board = $this->board($open, $channels);
        $asset = $this->assetId !== null ? DigitalAsset::query()->with('brand')->find($this->assetId) : null;
        $pending = $board->whereIn('plan_status', [AdvisorPlan::STATUS_QUEUED, AdvisorPlan::STATUS_RUNNING])->count();
        $draftsPending = $items->where('draft_status', 'queued')->count();
        $currency = $open->pluck('currency')->filter()->unique();
        $writes = ExternalWriteAction::query()->whereIn('advisor_item_id', $items->pluck('id'))->orderByDesc('id')->get()->groupBy('advisor_item_id');
        // Faz 5: sector-pack check of ready AI drafts, shown next to the draft (nothing stored here).
        $auditor = app(ComplianceAuditor::class);
        $brands = Brand::query()->with('sectors')->whereIn('id', $items->pluck('brand_id')->filter()->unique())->get()->keyBy('id');
        $packs = app(SectorPackRegistry::class);
        $compliance = $items->filter(fn (AdvisorItem $i): bool => $i->draft_status === 'ready' && is_array($i->draft) && ! isset($i->draft['error'])
            && $brands->has($i->brand_id) && $packs->forBrand($brands->get($i->brand_id)) !== [])
            ->mapWithKeys(fn (AdvisorItem $i): array => [$i->id => array_map(
                static fn (array $hit): array => ['label' => $hit['rule']->label, 'matched' => $hit['matched'], 'message' => $hit['rule']->message],
                $auditor->checkForBrand($brands->get($i->brand_id), ComplianceAuditor::flatten($i->draft), 'ai_draft'),
            )])->all();
        $writesPending = $writes->flatten()->whereIn('status', ['queued', 'running', 'undoing'])->count();

        return view('livewire.operator.advisor.advisor-panel', [
            'items' => $items,
            'explainInsight' => ($expanded = $this->expandedId !== null ? $items->firstWhere('id', $this->expandedId) : null) !== null ? $this->insightView('advisor.explain', $expanded) : null,
            'compliance' => $compliance,
            'counts' => $counts,
            'board' => $board,
            'asset' => $asset,
            'polling' => $pending > 0 || $draftsPending > 0 || $writesPending > 0,
            'writes' => $writes,
            'canWriteAds' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GOOGLE_ADS),
            'plansPending' => $pending,
            'kpis' => [
                'open' => $open->count(),
                'urgent' => $open->whereIn('severity', ['critical', 'high'])->count(),
                'waste' => $currency->count() <= 1 ? (float) $open->where('category', AdvisorCategory::Waste)->sum('impact_amount') : null,
                'currency' => $currency->first(),
                'accounts' => $board->where('bound', true)->count(),
            ],
            'categories' => AdvisorCategory::cases(),
            'channels' => array_map(static fn ($c): string => $c->label(), $channels->all()),
            'draftRules' => array_merge(...array_values(array_map(static fn ($c): array => $c->draftRules(), $channels->all()))),
            'customers' => $this->assetId === null ? Customer::query()->where('status', CustomerStatus::Active->value)->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    protected function insightSubject(string $kind, int $subjectId): ?Model
    {
        return $kind === 'advisor.explain' ? $this->query(ignoreCategory: true, ignoreStatus: true)->whereKey($subjectId)->first() : null;
    }

    /** @return Builder<AdvisorItem> */
    private function query(bool $ignoreCategory = false, bool $ignoreStatus = false): Builder
    {
        $query = AdvisorItem::query()->with(['brand', 'digitalAsset']);
        if ($this->assetId !== null) {
            $query->where('digital_asset_id', $this->assetId);
        } else {
            // Portfolio view: only operational assets (a passive customer's account keeps its own tab).
            app(ServiceScope::class)->constrain($query);
            if ($this->channelFilter !== '') {
                $query->where('channel', $this->channelFilter);
            }
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
    private function board(Collection $open, AdvisorChannels $channels): Collection
    {
        $types = $this->assetId === null && isset($channels->all()[$this->channelFilter]) ? [$channels->get($this->channelFilter)->assetType()] : $channels->assetTypes();
        $assets = DigitalAsset::query()
            ->with('brand')
            ->whereIn('type', $types)
            ->where('status', 'active')
            ->when($this->assetId !== null, fn (Builder $query) => $query->whereKey($this->assetId))
            ->when($this->assetId === null && ctype_digit($this->customerFilter), fn (Builder $query) => $query->whereHas('brand', fn (Builder $brand) => $brand->where('customer_id', (int) $this->customerFilter)))
            ->orderBy('name')
            ->get();
        $assetIds = $assets->pluck('id');
        $latestIds = AdvisorPlan::query()->selectRaw('max(id)')->whereIn('digital_asset_id', $assetIds)->groupBy('digital_asset_id');
        $completedIds = AdvisorPlan::query()->selectRaw('max(id)')->whereIn('digital_asset_id', $assetIds)->where('status', AdvisorPlan::STATUS_COMPLETED)->groupBy('digital_asset_id');
        $plans = AdvisorPlan::query()->whereIn('id', $latestIds)->orWhereIn('id', $completedIds)->orderByDesc('id')->get();
        $latest = $plans->unique('digital_asset_id')->keyBy('digital_asset_id');
        $completed = $plans->where('status', AdvisorPlan::STATUS_COMPLETED)->unique('digital_asset_id')->keyBy('digital_asset_id');
        $byAsset = $open->groupBy('digital_asset_id');

        return $assets->map(function (DigitalAsset $asset) use ($latest, $completed, $byAsset, $channels): array {
            $channel = $channels->forAssetType((string) $asset->type);
            $plan = $latest->get($asset->id);
            $done = $completed->get($asset->id);
            $rows = $byAsset->get($asset->id, collect());

            return [
                'id' => $asset->id,
                'name' => $asset->name,
                'brand' => $asset->brand?->name,
                'channel' => $channel?->label(),
                'url' => $channel?->assetUrl($asset->id),
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
        $this->item($id)->forceFill(['status' => $status->value, 'resolved_at' => now(), 'resolved_by' => auth()->id(), 'verification' => null, 'verified_at' => null, 'snoozed_until' => null])->save();
        $this->flash($message);
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->messageTone = $tone;
    }
}
