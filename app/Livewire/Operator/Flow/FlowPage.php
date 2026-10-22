<?php

namespace App\Livewire\Operator\Flow;

use App\Services\Work\FlowFeed;
use App\Services\Work\OperatorTime;
use App\Support\Roles;
use App\Support\Work\WorkStage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Akış (Aşama 4, 2026-10-22; prototype approved by yakup the same day): every piece of work of today, live. The live
 * part (counts and the job log) is its own small component that polls (FlowLive); this page adds what changes slowly:
 * the five stages of the four channels and the operator's own time.
 */
#[Layout('operator.layouts.app')]
#[Title('Akış')]
final class FlowPage extends Component
{
    public function render(FlowFeed $feed, OperatorTime $time): View
    {
        $stages = [];
        try {
            $stages = $feed->stages();
        } catch (Throwable $error) {
            report($error);
        }

        return view('livewire.operator.flow.flow-page', [
            'stages' => $stages,
            'channels' => WorkStage::CHANNELS,
            'stageLabels' => WorkStage::LABELS,
            'stageHints' => WorkStage::HINTS,
            'time' => auth()->user()?->hasRole(Roles::ADMIN) ? $time->week() : null,
        ]);
    }
}
