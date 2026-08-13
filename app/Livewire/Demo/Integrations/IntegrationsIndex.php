<?php

namespace App\Livewire\Demo\Integrations;

use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Integrations')]
class IntegrationsIndex extends Component
{
    #[Url(as: 'section', history: true)]
    public string $section = '';

    public function mount(): void
    {
        if ($this->section === 'site_connectors') {
            $this->redirect(route('demo.integrations.site-connectors'), navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.demo.integrations.integrations-index', [
            'groups' => GlobalOperatingFixtures::integrationsHub(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
