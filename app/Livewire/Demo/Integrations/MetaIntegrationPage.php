<?php

namespace App\Livewire\Demo\Integrations;

use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Integration')]
class MetaIntegrationPage extends Component
{
    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    public function mount(): void
    {
        if (! in_array($this->tab, ['overview', 'connectors', 'resources', 'activity'], true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'connectors', 'resources', 'activity'], true)) {
            $this->tab = $tab;
        }
    }

    public function render(MetaIntegrationReadModel $readModel): View
    {
        $integration = $readModel->detail();
        $readModel->assertNoSecrets($integration);

        return view('livewire.demo.integrations.meta-integration', [
            'integration' => $integration,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
