<?php

namespace App\Livewire\Operator\Flow;

use App\Services\Work\FlowFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Top bar pill (approved prototype): "● 3 çalışıyor · 618 bitti", one click to Akış. Counts are shared for a few
 * seconds between open tabs; refreshed by the top bar's one pulse (30 s, 5 s while work runs).
 */
final class FlowPill extends Component
{
    /** The top bar's one pulse (header.blade.php) refreshes the pill; it polls nothing on its own. */
    #[On('header-pulse')]
    public function refreshNow(): void {}

    public function render(): View
    {
        $counts = ['queue' => 0, 'run' => 0, 'done' => 0, 'err' => 0];
        try {
            $counts = Cache::remember('flow-feed.pill', 8, fn (): array => app(FlowFeed::class)->counts());
        } catch (Throwable $error) {
            report($error);
        }
        if ($counts['run'] > 0) {
            $this->dispatch('header-pulse-busy');
        }

        return view('livewire.operator.flow.flow-pill', ['counts' => $counts]);
    }
}
