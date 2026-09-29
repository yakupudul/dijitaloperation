<?php

namespace App\Livewire\Demo;

use App\Enums\Observability\OperationalAlertState;
use App\Models\AnalystDecision;
use App\Models\Brand;
use App\Models\Observability\OperationalAlert;
use App\Services\Analyst\AnalystRegistry;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Bugün: the portfolio in one list — every operational brand with its most urgent open card and the open-card count
 * per channel (Arama · Harita · Google Ads · Meta). The work itself happens on the brand workspace.
 */
#[Layout('operator.layouts.app')]
#[Title('Bugün')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.demo.dashboard', [
            'rows' => $this->rows(),
            'channels' => array_map(fn (array $c): string => $c[0], AnalystRegistry::CHANNELS),
            'systemAlerts' => $this->systemAlerts(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @return list<array{id: int, name: string, top: array{title: string, why: string, channel: string}|null, counts: array<string, int>, total: int}>
     */
    private function rows(): array
    {
        try {
            $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name']);
            $open = AnalystDecision::query()->whereIn('brand_id', $brands->pluck('id')->all() ?: [0])->actionable()
                ->orderBy('priority')->orderByDesc('last_seen_at')->orderBy('id')->get(['id', 'brand_id', 'channel', 'title', 'why', 'priority'])->groupBy('brand_id');
            $rows = $brands->map(function (Brand $brand) use ($open): array {
                $decisions = $open->get($brand->id, collect());
                $top = $decisions->first();

                return [
                    'id' => (int) $brand->id, 'name' => (string) $brand->name,
                    'top' => $top === null ? null : ['title' => (string) $top->title, 'why' => (string) $top->why, 'channel' => $top->channel, 'priority' => (int) $top->priority],
                    'counts' => $decisions->countBy('channel')->map(fn ($n): int => (int) $n)->all(), 'total' => $decisions->count(),
                ];
            })->all();
            usort($rows, fn (array $a, array $b): int => [($a['top']['priority'] ?? 9), -$a['total'], $a['name']] <=> [($b['top']['priority'] ?? 9), -$b['total'], $b['name']]);

            return $rows;
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
