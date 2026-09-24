<?php

namespace App\Livewire\Demo;

use App\Enums\Observability\OperationalAlertState;
use App\Models\AssetAlert;
use App\Models\Observability\OperationalAlert;
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
            'systemAlerts' => $this->systemAlerts(),
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

    /**
     * Faz 13: open operational alerts (collection failure, reconnect needed, quota, expiring token, stopped worker)
     * reach the operator on the home screen, not only the admin's phone.
     *
     * @return array{critical: int, warning: int, top: ?string}
     */
    private function systemAlerts(): array
    {
        try {
            $open = OperationalAlert::query()->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value]);
            $top = (clone $open)->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 else 2 end")->orderByDesc('last_observed_at')->value('title');

            return [
                'critical' => (clone $open)->where('severity', 'CRITICAL')->count(),
                'warning' => (clone $open)->where('severity', 'WARNING')->count(),
                'top' => $top !== null ? (string) $top : null,
            ];
        } catch (Throwable) {
            return ['critical' => 0, 'warning' => 0, 'top' => null];
        }
    }
}
