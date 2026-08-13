<?php

namespace App\Livewire\Demo\Integrations;

use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Support\Demo\DemoState;
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

    public function mount(): void
    {
        if (! in_array($this->tab, ['overview', 'connectors', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'connectors', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = $tab;
        }
    }

    public function bindResource(string $resourceId): void
    {
        // Production binding workflow is Prompt 16 — do not fake binds as real.
        DemoState::flash(
            'Resource selection and binding is not productionized yet (Prompt 16). Discovered resources are shown read-only.',
            'info',
        );
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
        // OAuth revocation / credential lifecycle is Prompt 14.
        DemoState::flash(
            'Disconnect was not executed. Google OAuth revocation is owned by Prompt 14.',
            'info',
        );
    }

    public function render(GoogleIntegrationReadModel $readModel): View
    {
        $integration = $readModel->detail();
        $readModel->assertNoSecrets($integration);

        return view('livewire.demo.integrations.google-integration', [
            'integration' => $integration,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
