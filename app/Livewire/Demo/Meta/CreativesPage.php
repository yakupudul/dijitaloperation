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
#[Title('Meta Creatives')]
class CreativesPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->mountPeriod();
    }

    public function render(): View
    {
        return view('livewire.demo.meta.creatives', [
            'assetId' => $this->assetId,
            'creatives' => DemoCatalog::metaCreatives($this->period),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
