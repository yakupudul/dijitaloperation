<?php

namespace App\Livewire\Demo;

use App\Enums\Observability\OperationalAlertState;
use App\Models\AssetAlert;
use App\Models\Observability\OperationalAlert;
use App\Services\CommandCenter\CommandCenter;
use App\Services\Operator\OperatorExecutionReadService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Ana sayfa')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.demo.dashboard', [
            'dashboard' => app(OperatorExecutionReadService::class)->dashboard('my_work'),
            'weeklyTop' => $this->weeklyTop(),
            'commandSummary' => $this->commandSummary(),
            'alerts' => $this->openAlerts(),
            'systemAlerts' => $this->systemAlerts(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * "Önce bunlar": the first command-center items across every source (max 2 per brand).
     *
     * @return list<array<string, mixed>>
     */
    private function weeklyTop(): array
    {
        try {
            return app(CommandCenter::class)->top(8, 2);
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /** @return array{total: int, critical: int, money: float, clicks: float, brands: int} */
    private function commandSummary(): array
    {
        try {
            return app(CommandCenter::class)->summary();
        } catch (Throwable) {
            return ['total' => 0, 'critical' => 0, 'money' => 0.0, 'clicks' => 0.0, 'brands' => 0];
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
