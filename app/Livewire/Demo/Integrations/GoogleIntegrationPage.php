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
#[Title('Google Integration')]
class GoogleIntegrationPage extends Component
{
    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    public bool $confirmDisconnect = false;

    public ?string $boundResourceId = null;

    public function mount(): void
    {
        if (! in_array($this->tab, ['overview', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = $tab;
        }
    }

    public function bindResource(string $resourceId): void
    {
        $this->boundResourceId = $resourceId;
        DemoState::flash('Resource bound to a Digital Asset in Demo Mode (session only — no provider write).');
    }

    public function openDisconnect(): void
    {
        $this->confirmDisconnect = true;
    }

    public function cancelDisconnect(): void
    {
        $this->confirmDisconnect = false;
    }

    public function confirmDisconnectAction(): void
    {
        $this->confirmDisconnect = false;
        DemoState::flash('Disconnect cancelled in Demo Mode — destructive provider disconnect is not executed.', 'info');
    }

    public function render(): View
    {
        return view('livewire.demo.integrations.google-integration', [
            'integration' => GlobalOperatingFixtures::googleIntegration(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
