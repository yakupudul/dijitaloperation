<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Ad Sets')]
class AdSetsPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $statusFilter = 'all';

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->mountPeriod();
        $stored = DemoState::getFilter('meta_adset_status');
        $this->statusFilter = is_string($stored) && $stored !== '' ? $stored : 'all';
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        DemoState::setFilter('meta_adset_status', $status === 'all' ? null : $status);
    }

    public function render(): View
    {
        $rows = DemoCatalog::metaAdSetsList($this->period);
        if ($this->statusFilter !== 'all') {
            $needle = strtoupper($this->statusFilter);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => strtoupper((string) ($row['status'] ?? '')) === $needle
            ));
        }

        return view('livewire.demo.meta.adsets', [
            'assetId' => $this->assetId,
            'adsets' => $rows,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
