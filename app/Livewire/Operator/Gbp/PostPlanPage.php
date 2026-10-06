<?php

namespace App\Livewire\Operator\Gbp;

use App\Jobs\Gbp\FillGbpPostQueueJob;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpPostQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme gönderileri (`/gbp-posts`, ADR-078): the automatic Business Profile posts of every location for the next
 * 30 days on one screen. The Admin approves them in bulk ("Tümünü onayla") or per location, edits or skips a row
 * (a skipped day is planned again) and retries a post that could not be published.
 */
#[Layout('operator.layouts.app')]
#[Title('İşletme gönderileri')]
final class PostPlanPage extends Component
{
    /** Location whose days are open. */
    #[Url(as: 'isletme')]
    public ?int $location = null;

    public ?int $editing = null;

    public string $editText = '';

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function open(int $assetId): void
    {
        $this->location = $this->location === $assetId ? null : $assetId;
        $this->editing = null;
    }

    public function approveAll(?int $assetId = null): void
    {
        $count = app(GbpPostQueue::class)->approveAll(auth()->user(), $assetId);
        $this->message = $count > 0 ? $count.' gönderi onaylandı; günü gelince yayınlanır.' : 'Onay bekleyen gönderi yok.';
    }

    public function approve(int $id, GbpPostQueue $queue): void
    {
        $queue->approve(auth()->user(), [$id]);
    }

    public function skip(int $id, GbpPostQueue $queue): void
    {
        $queue->skip(auth()->user(), $this->post($id));
        $this->message = 'Atlandı; o gün bir sonraki planlamada yeniden doldurulur.';
    }

    public function retry(int $id, GbpPostQueue $queue): void
    {
        try {
            $queue->retry(auth()->user(), $this->post($id));
            $this->message = 'Bugün yeniden denenecek.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function startEdit(int $id): void
    {
        $this->editing = $id;
        $this->editText = (string) $this->post($id)->summary;
    }

    public function saveEdit(GbpPostQueue $queue): void
    {
        if ($this->editing === null) {
            return;
        }
        try {
            $queue->edit(auth()->user(), $this->post($this->editing), $this->editText);
            $this->editing = null;
        } catch (ValidationException $exception) {
            $this->addError('editText', (string) collect($exception->errors())->flatten()->first());
        }
    }

    /** Plans the empty days now (all locations, or the open one). */
    public function fillNow(): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        $ids = $this->location !== null ? collect([$this->location]) : GbpPostQueue::locations()->pluck('digital_assets.id');
        foreach ($ids as $id) {
            Cache::put(FillGbpPostQueueJob::stateKey((int) $id), ['status' => 'running', 'message' => 'Hazırlanıyor…'], now()->addHour());
            FillGbpPostQueueJob::dispatch((int) $id, true);
        }
        $this->message = $ids->count().' işletme için boş günler hazırlanıyor.';
    }

    public function render(): View
    {
        $today = GbpPostQueue::today();
        $from = $today->toDateString();
        $to = $today->addDays(GbpPostQueue::HORIZON_DAYS)->toDateString();
        $locations = GbpPostQueue::locations()->with('brand:id,name')->orderBy('digital_assets.brand_id')->orderBy('digital_assets.name')
            ->get(['digital_assets.id', 'digital_assets.name', 'digital_assets.brand_id']);
        $counts = GbpQueuedPost::query()->whereIn('digital_asset_id', $locations->pluck('id'))->whereBetween('publish_on', [$from, $to])
            ->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED, GbpQueuedPost::PUBLISHED])
            ->selectRaw('digital_asset_id, status, count(*) as n')->groupBy('digital_asset_id', 'status')->get()
            ->groupBy('digital_asset_id')->map(fn ($rows): array => $rows->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all());
        $rows = $this->location !== null ? GbpQueuedPost::query()->where('digital_asset_id', $this->location)
            ->whereBetween('publish_on', [$today->subDays(7)->toDateString(), $to])->with(['page:id,title,url', 'writeAction:id,status,error'])
            ->orderBy('publish_on')->orderBy('id')->get() : collect();

        return view('livewire.operator.gbp.post-plan-page', [
            'locations' => $locations,
            'counts' => $counts,
            'drafts' => (int) $counts->sum(fn (array $c): int => $c[GbpQueuedPost::DRAFT] ?? 0),
            'rows' => $rows,
            'states' => $locations->mapWithKeys(fn (DigitalAsset $l): array => [$l->id => Cache::get(FillGbpPostQueueJob::stateKey((int) $l->id))])->filter()->all(),
            'angles' => GbpPostQueue::ANGLES,
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP),
            'horizon' => GbpPostQueue::HORIZON_DAYS,
            'today' => $from,
        ]);
    }

    private function post(int $id): GbpQueuedPost
    {
        return GbpQueuedPost::query()->with('digitalAsset.brand', 'writeAction')->findOrFail($id);
    }
}
