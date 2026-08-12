<?php

namespace App\Livewire\Demo\Integrations;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Integrations')]
class IntegrationsIndex extends Component
{
    public function render(): View
    {
        return view('livewire.demo.integrations.integrations-index', [
            'integrations' => DemoCatalog::integrations(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
