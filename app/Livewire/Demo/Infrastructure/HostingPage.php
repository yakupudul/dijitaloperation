<?php

namespace App\Livewire\Demo\Infrastructure;

use App\Support\Demo\DemoCatalog;
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

        $this->redirect(route('demo.website', [
            'assetId' => DemoCatalog::WEBSITE_ASSET_ID,
            'tab' => 'infrastructure',
        ]), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.demo.infrastructure.hosting');
    }
}
