<?php

namespace App\Livewire\Operator\Brain;

use App\Models\Brand;
use App\Services\Brain\BrainLabels;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Hizmet Beyni › Öneriler: what the Brain recommends per brand and channel, with its evidence and how proven the
 * method is. Operators mark them done (the effect is then measured) or dismiss them, one by one or in bulk.
 */
#[Layout('operator.layouts.app')]
#[Title('Beyin önerileri')]
final class RecommendationsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $channel = '';

    #[Url]
    public ?int $brand = null;

    #[Url]
    public ?int $service = null;

    #[Url]
    public string $status = 'open';

    /** @var list<int> */
    public array $bulkIds = [];

    public ?int $expanded = null;

    public function updating(string $name): void
    {
        if (in_array($name, ['channel', 'brand', 'service', 'status'], true)) {
            $this->resetPage();
            $this->bulkIds = [];
        }
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function markDone(?int $id = null): void
    {
        $count = $this->resolve($id !== null ? [$id] : $this->bulkIds, 'done');
        DemoState::flash($count.' öneri yapıldı olarak işaretlendi. Etkisi 28 ve 56 gün sonra benzer, uygulanmamış sayfalarla karşılaştırılarak ölçülür.');
    }

    public function dismiss(?int $id = null): void
    {
        $count = $this->resolve($id !== null ? [$id] : $this->bulkIds, 'dismissed');
        DemoState::flash($count.' öneri kapatıldı; tekrar gösterilmez.');
    }

    /** @param  list<int>  $ids */
    private function resolve(array $ids, string $status): int
    {
        abort_unless(auth()->user()?->is_active, 403);
        $this->bulkIds = [];

        return DB::table('brain_recommendations')->whereIn('id', array_map('intval', $ids))->where('status', 'open')
            ->update(['status' => $status, 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'updated_at' => now()]);
    }

    private function query(): Builder
    {
        return DB::table('brain_recommendations as r')
            ->leftJoin('brands as b', 'b.id', '=', 'r.brand_id')
            ->leftJoin('service_catalog_names as n', function ($join): void {
                $join->on('n.service_catalog_item_id', '=', 'r.service_id')->where('n.is_primary', true);
            })
            ->when($this->channel !== '', fn ($q) => $q->where('r.channel', $this->channel))
            ->when($this->brand !== null, fn ($q) => $q->where('r.brand_id', $this->brand))
            ->when($this->service !== null, fn ($q) => $q->where('r.service_id', $this->service))
            ->when($this->status !== 'all', fn ($q) => $q->where('r.status', $this->status));
    }

    public function render(): View
    {
        $rows = $this->query()->orderByRaw("case r.basis when 'validated' then 0 when 'observational' then 1 else 2 end")
            ->orderByRaw('coalesce(r.impact, 0) desc')->orderByDesc('r.id')
            ->select(['r.*', 'b.name as brand_name', 'n.raw_label as service_name'])->paginate(40);

        return view('livewire.operator.brain.recommendations', [
            'rows' => $rows,
            'visibleIds' => collect($rows->items())->where('status', 'open')->pluck('id')->map('intval')->values()->all(),
            'brands' => Brand::query()->whereIn('id', DB::table('brain_recommendations')->select('brand_id'))->orderBy('name')->pluck('name', 'id'),
            'channels' => BrainLabels::CHANNELS,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
