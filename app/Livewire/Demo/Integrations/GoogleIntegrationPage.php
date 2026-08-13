<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\Google\GoogleOAuthService;
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
        DemoState::flash(
            'Resource selection and binding is not productionized yet (Prompt 16). Discovered resources are shown read-only.',
            'info',
        );
    }

    public function discoverResources(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        if (! $integration instanceof CoreIntegration) {
            DemoState::flash('No Google Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverGoogleResourcesService::class)->discover(
            $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration,
            $user,
        );

        DemoState::flash($result['message'], 'info');
    }

    public function bootstrapAndConnect(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::GOOGLE);
        $result = app(GoogleOAuthService::class)->beginAuthorization($integration, $user);

        if (isset($result['error'])) {
            DemoState::flash($result['error'], 'info');

            return;
        }

        $this->redirect($result['url']);
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

        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        if (! $integration instanceof CoreIntegration) {
            DemoState::flash('No Google Integration is configured.', 'info');

            return;
        }

        $result = app(GoogleOAuthService::class)->revokeAuthorization(
            $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration,
        );

        DemoState::flash($result['message'], $result['ok'] ? 'info' : 'info');
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
