<?php

namespace App\Livewire\Operator\Work;

use App\Models\Brand;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Komuta merkezi: every brand's and every channel's open work in one ranked list. Closing an item here closes it in
 * its own screen too; the same problem reported by two producers is shown once.
 */
#[Layout('operator.layouts.app')]
#[Title('Komuta merkezi')]
final class CommandCenterPage extends Component
{
    use WithPagination;

    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $source = '';

    #[Url]
    public string $severity = '';

    /** @var list<string> */
    public array $bulkIds = [];

    public function updating(string $name): void
    {
        if (in_array($name, ['brand', 'source', 'severity'], true)) {
            $this->resetPage();
            $this->bulkIds = [];
        }
    }

    public function act(string $action, ?string $key = null, int $days = 7): void
    {
        abort_unless(in_array($action, ['done', 'snooze', 'dismiss'], true), 422);
        $user = auth()->user();
        abort_unless($user?->is_active, 403);
        $keys = $key !== null ? [$key] : $this->bulkIds;
        $count = app(CommandCenter::class)->act($keys, $action, $user, $days);
        $this->bulkIds = [];
        DemoState::flash(match ($action) {
            'done' => $count.' iş yapıldı olarak işaretlendi.',
            'snooze' => $count.' iş '.$days.' gün ertelendi.',
            default => $count.' iş kapatıldı.',
        });
    }

    public function render(CommandCenter $center): View
    {
        $filtered = $center->items(['brand_id' => $this->brand, 'source' => $this->source, 'severity' => $this->severity]);
        $all = $this->brand === null && $this->source === '' && $this->severity === '' ? $filtered : $center->items();
        $page = $this->getPage();
        $rows = new LengthAwarePaginator($filtered->forPage($page, 50)->values(), $filtered->count(), 50, $page, ['path' => request()->url()]);

        return view('livewire.operator.work.command-center', [
            'rows' => $rows,
            'summary' => $center->summary($all),
            'bySource' => $all->countBy('source')->all(),
            'brands' => Brand::query()->whereIn('id', $all->pluck('brand_id')->filter()->unique())->orderBy('name')->pluck('name', 'id'),
            'visibleKeys' => $rows->getCollection()->pluck('key')->all(),
        ]);
    }
}
