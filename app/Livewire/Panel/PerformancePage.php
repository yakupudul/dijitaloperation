<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelMembership;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelPerformance;
use App\Panel\Support\PanelPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Performans: what became of the people who arrived, counted from the brand's own records. */
#[Layout('panel.layouts.app')]
#[Title('Performans')]
final class PerformancePage extends Component
{
    use PanelPage;

    public const array PERIODS = ['7' => 'Son 7 gün', '30' => 'Son 30 gün', '90' => 'Son 90 gün', 'ay' => 'Bu ay', 'gecen' => 'Geçen ay'];

    #[Url(as: 'donem')]
    public string $period = '30';

    protected function permission(): string
    {
        return 'menu.performance';
    }

    public function render(PanelLeads $leads, PanelPerformance $performance): View
    {
        if (! isset(self::PERIODS[$this->period])) {
            $this->period = '30';
        }
        $now = now('Europe/Istanbul');
        [$from, $to] = match ($this->period) {
            'ay' => [$now->copy()->startOfMonth(), $now->copy()->addDay()->startOfDay()],
            'gecen' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()],
            default => [$now->copy()->subDays((int) $this->period - 1)->startOfDay(), $now->copy()->addDay()->startOfDay()],
        };
        $team = $this->may('performance.team') && $this->may('leads.all');

        return view('livewire.panel.performance', [
            'data' => $performance->sales($leads->visible(), $from->copy()->utc(), $to->copy()->utc()),
            'from' => $from,
            'to' => $to->copy()->subSecond(),
            'showTeam' => $team,
            'names' => $team ? PanelMembership::query()->with('user:id,name')->where('brand_id', $this->panel()->brandId())->get()->pluck('user.name', 'panel_user_id') : collect(),
        ]);
    }
}
