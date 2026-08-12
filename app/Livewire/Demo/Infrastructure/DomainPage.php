<?php

namespace App\Livewire\Demo\Infrastructure;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Domain')]
class DomainPage extends Component
{
    public string $assetId = DemoCatalog::DOMAIN_ASSET_ID;

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::DOMAIN_ASSET_ID;
    }

    public function render(): View
    {
        return view('livewire.demo.infrastructure.domain', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => DemoCatalog::domainOverview(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
