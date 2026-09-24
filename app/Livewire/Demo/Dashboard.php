<?php

namespace App\Livewire\Demo;

use App\Models\AssetAlert;
use App\Services\Advisor\AdvisorWorkQueue;
use App\Services\Operator\OperatorExecutionReadService;
use App\Services\Opportunities\OpportunityReadService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

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
            'weeklyTop' => $this->weeklyTop(),
            'alerts' => $this->openAlerts(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * "Bu haftanın en önemli 5 işi" across SEO Görevleri and every advisor channel (max 2 per brand).
     *
     * @return list<array<string, mixed>>
     */
    private function weeklyTop(): array
    {
        try {
            return app(AdvisorWorkQueue::class)->top(5);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Open asset alerts, most severe first (critical → low), newest first within a severity.
     *
     * @return Collection<int, AssetAlert>
     */
    private function openAlerts(): Collection
    {
        try {
            return AssetAlert::query()->active()
                ->with(['digitalAsset', 'brand'])
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
                ->latest('first_detected_at')
                ->limit(8)
                ->get();
        } catch (Throwable) {
            return new Collection;
        }
    }
}
