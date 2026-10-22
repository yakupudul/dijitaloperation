<?php

namespace App\Livewire\Operator\Flow;

use App\Services\Work\FlowFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * Top bar pill (approved prototype): "● 3 çalışıyor · 618 bitti", one click to Akış. Counts are shared for a few
 * seconds between open tabs; polled every 10 s while work runs, every 60 s otherwise.
 */
final class FlowPill extends Component
{
    public function render(): View
    {
        $counts = ['queue' => 0, 'run' => 0, 'done' => 0, 'err' => 0];
        try {
            $counts = Cache::remember('flow-feed.pill', 8, fn (): array => app(FlowFeed::class)->counts());
        } catch (Throwable $error) {
            report($error);
        }

        return view('livewire.operator.flow.flow-pill', ['counts' => $counts]);
    }
}
