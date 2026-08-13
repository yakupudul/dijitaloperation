<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\CoreIntegration;
use App\Services\Integrations\Meta\DiscoverMetaResourcesService;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Services\Integrations\Meta\SelectMetaDiscoveryContextService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
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

    public bool $confirmDisconnect = false;

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

    public function bootstrapAndConnect(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::META);
        $result = app(MetaOAuthService::class)->beginAuthorization($integration, $user);

        if (isset($result['error'])) {
            DemoState::flash($result['error'], 'info');

            return;
        }

        $this->redirect($result['url']);
    }

    public function discoverBusinesses(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverMetaResourcesService::class)->discoverBusinesses($integration, $user);
        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function discoverAdAccounts(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverMetaResourcesService::class)->discoverAdAccounts($integration, $user);
        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function refreshResources(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverMetaResourcesService::class)->refreshInventory($integration, $user);
        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function toggleBusinessSelection(string $resourceId): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $selection = app(SelectMetaDiscoveryContextService::class);
        $activeIds = $selection->activeBusinessResourceIds($integration);

        if (in_array((int) $resourceId, $activeIds, true)) {
            $selection->deselect($integration, $resourceId, $user);
            DemoState::flash('Business removed from discovery context. Existing Ad Account inventory is preserved.', 'info');
        } else {
            $selection->select($integration, $resourceId, $user);
            DemoState::flash('Business selected as Ad Account discovery context — not a Digital Asset binding.', 'info');
        }

        $this->tab = 'resources';
    }

    public function askDisconnect(): void
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

        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(MetaOAuthService::class)->disconnect($integration);
        DemoState::flash($result['message'], 'info');
    }

    public function render(MetaIntegrationReadModel $readModel): View
    {
        $integration = $readModel->detail();
        $readModel->assertNoSecrets($integration);

        return view('livewire.demo.integrations.meta-integration', [
            'integration' => $integration,
            'flash' => DemoState::pullFlash(),
            'confirmDisconnect' => $this->confirmDisconnect,
        ]);
    }

    private function metaIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with(['providerCredential'])
            ->where('provider', ProviderRegistry::META)
            ->first();
    }
}
