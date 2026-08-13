<?php

namespace App\Livewire\Demo\Integrations;

use App\Support\Demo\DemoState;
use App\Support\Demo\SiteConnectorFixtures;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Site Connectors')]
class SiteConnectorsIndex extends Component
{
    public function render(): View
    {
        return view('livewire.demo.integrations.site-connectors-index', [
            'connectors' => SiteConnectorFixtures::catalog(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
