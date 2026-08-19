<?php

namespace App\Livewire\Demo;

use App\Services\Operator\OperatorExecutionReadService;
use App\Services\Opportunities\OpportunityReadService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Url(as: 'mode', history: true)]
    public string $mode = 'my_work';

    public function mount(): void
    {
        if (! in_array($this->mode, ['my_work', 'agency'], true)) {
            $this->mode = 'my_work';
        }
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['my_work', 'agency'], true)) {
            $this->mode = $mode;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.dashboard', [
            'dashboard' => app(OperatorExecutionReadService::class)->dashboard($this->mode),
            'growthOpportunities' => collect(app(OpportunityReadService::class)->forListPresentation())
                ->whereIn('status', ['open', 'reviewing'])
                ->take(3)
                ->values()
                ->all(),
            'recentValue' => [],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
