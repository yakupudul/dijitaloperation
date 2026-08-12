<?php

namespace App\Livewire\Demo\Infrastructure;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Hosting')]
class HostingPage extends Component
{
    public string $assetId = DemoCatalog::HOSTING_ASSET_ID;

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::HOSTING_ASSET_ID;
    }

    public function render(): View
    {
        return view('livewire.demo.infrastructure.hosting', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => DemoCatalog::hostingOverview(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
