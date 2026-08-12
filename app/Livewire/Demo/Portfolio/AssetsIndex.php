<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Digital Assets')]
class AssetsIndex extends Component
{
    public bool $showWizard = false;

    public int $step = 1;

    public string $assetType = 'website';

    public string $assetName = '';

    public string $connectionMode = 'public';

    public function openWizard(): void
    {
        $this->showWizard = true;
        $this->step = 1;
        $this->assetType = 'website';
        $this->assetName = '';
        $this->connectionMode = 'public';
    }

    public function closeWizard(): void
    {
        $this->showWizard = false;
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate(['assetType' => 'required|string']);
            $this->step = 2;

            return;
        }

        if ($this->step === 2) {
            $this->validate(['assetName' => 'required|string|min:2|max:120']);
            $this->step = 3;

            return;
        }

        DemoState::flash('Asset “'.$this->assetName.'” added in Demo Mode (session only — not written to operator DB).');
        $this->closeWizard();
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.assets-index', [
            'assets' => DemoCatalog::assets(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
