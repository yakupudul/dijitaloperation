<?php

namespace App\Livewire\Demo\Integrations;

use App\Livewire\Demo\Integrations\Concerns\ManagesOperatorCredentials;
use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoAccountService;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Sales\IntentSearchConfig;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('DataForSEO')]
class DataForSeoIntegrationPage extends Component
{
    use ManagesOperatorCredentials;

    public string $login = '';

    public string $password = '';

    public bool $clearPassword = false;

    public bool $confirmRemove = false;

    public bool $salesIntentPaidCalls = false;

    public function mount(): void
    {
        $this->hydrateForm();
        $this->salesIntentPaidCalls = IntentSearchConfig::paidCallsEnabled();
    }

    public function dehydrate(): void
    {
        $this->password = '';
        $this->clearPassword = false;
    }

    public function saveConfiguration(DataForSeoProviderCredentialService $service): void
    {
        $user = $this->credentialManager();
        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::DATAFORSEO);

        $service->save($integration, [
            'login' => $this->login,
            'password' => $this->password,
            'clear_password' => $this->clearPassword,
        ], $user);

        $this->password = '';
        $this->clearPassword = false;
        $this->hydrateForm($integration->fresh(['providerCredential']));
        DemoState::flash(__('operator.flash.dataforseo_saved'), 'info');
    }

    public function saveSalesIntentRuntime(): void
    {
        $this->credentialManager();
        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::DATAFORSEO);
        $config = is_array($integration->config) ? $integration->config : [];
        $config[IntentSearchConfig::RUNTIME_PAID_CALLS_KEY] = $this->salesIntentPaidCalls;
        $integration->config = $config;
        $integration->save();

        DemoState::flash(
            $this->salesIntentPaidCalls
                ? 'Sales Intent live paid calls enabled. Each run still requires explicit operator consent.'
                : 'Sales Intent live paid calls disabled.',
            'info',
        );
    }

    public function testConfiguration(DataForSeoAccountService $account): void
    {
        $this->credentialManager();
        $integration = $this->integration();

        if ($integration === null || ! app(DataForSeoCredentialResolver::class)->isConfigured($integration)) {
            DemoState::flash(__('operator.flash.configure_dataforseo'), 'info');

            return;
        }

        $result = $account->testConnection($integration);
        DemoState::flash($result['message'], 'info');
    }

    public function askRemove(): void
    {
        $this->credentialManager();
        $this->confirmRemove = true;
    }

    public function cancelRemove(): void
    {
        $this->confirmRemove = false;
    }

    public function removeConfiguration(DataForSeoProviderCredentialService $service): void
    {
        $user = $this->credentialManager();
        $this->confirmRemove = false;
        $integration = $this->integration();

        if ($integration === null) {
            DemoState::flash(__('operator.flash.no_dataforseo'), 'info');

            return;
        }

        $service->remove($integration, $user);
        $this->login = '';
        $this->hydrateForm();
        DemoState::flash(__('operator.flash.dataforseo_removed'), 'info');
    }

    public function render(): View
    {
        $integration = $this->integration();
        $resolver = app(DataForSeoCredentialResolver::class);
        $configured = $integration instanceof CoreIntegration && $resolver->isConfigured($integration);
        $passwordConfigured = $integration instanceof CoreIntegration && $resolver->hasDatabasePassword($integration);
        $config = is_array($integration?->config) ? $integration->config : [];

        return view('livewire.demo.integrations.dataforseo-integration', [
            'flash' => DemoState::pullFlash(),
            'canManageCredentials' => $this->canManageCredentials(),
            'configured' => $configured,
            'statusLabel' => $configured ? 'Configured' : 'Not configured',
            'passwordConfigured' => $passwordConfigured,
            'connectionStatus' => is_string($config['connection_status'] ?? null) ? $config['connection_status'] : null,
            'lastTestedAt' => is_string($config['last_tested_at'] ?? null) ? $config['last_tested_at'] : null,
            'accountLogin' => is_string($config['account_login'] ?? null) ? $config['account_login'] : null,
            'fixturesEnabled' => IntentSearchConfig::fixturesEnabled(),
        ]);
    }

    private function integration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::DATAFORSEO)
            ->first();
    }

    private function hydrateForm(?CoreIntegration $integration = null): void
    {
        $integration ??= $this->integration();
        $this->password = '';

        if (! $integration instanceof CoreIntegration) {
            $this->login = '';

            return;
        }

        $this->login = app(DataForSeoCredentialResolver::class)->databaseLogin($integration) ?? '';
    }
}
