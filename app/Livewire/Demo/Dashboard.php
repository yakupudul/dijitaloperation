<?php

namespace App\Livewire\Demo;

use App\Enums\Observability\OperationalAlertState;
use App\Models\Brand;
use App\Models\Observability\OperationalAlert;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Bugün: every operational brand in one list. Faz 9 adds the applied-suggestion results (worked / not) per brand;
 * until then the list is the entry point to each brand's workspace.
 */
#[Layout('operator.layouts.app')]
#[Title('Bugün')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.demo.dashboard', [
            'rows' => $this->rows(),
            'systemAlerts' => $this->systemAlerts(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @return list<array{id: int, name: string, customer: ?string, assets: int}>
     */
    private function rows(): array
    {
        try {
            return Brand::query()->operational()->with('customer')->withCount('digitalAssets')->orderBy('name')->get()
                ->map(fn (Brand $brand): array => [
                    'id' => (int) $brand->id,
                    'name' => (string) $brand->name,
                    'customer' => $brand->customer?->name,
                    'assets' => (int) ($brand->digital_assets_count ?? 0),
                ])->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * Open operational alerts (collection failure, reconnect needed, quota, stopped worker): one line on Bugün.
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
