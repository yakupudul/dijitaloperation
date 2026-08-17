<?php

namespace App\Livewire\Demo\Integrations;

use App\Livewire\Demo\Integrations\Concerns\ManagesOperatorCredentials;
use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicConnectionService;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Anthropic\AnthropicProviderCredentialService;
use App\Services\Integrations\Gemini\GeminiConnectionService;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\Gemini\GeminiProviderCredentialService;
use App\Services\Integrations\OpenAi\OpenAiConnectionService;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Demo\DemoState;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('operator.layouts.app')]
class AiProviderIntegrationPage extends Component
{
    use ManagesOperatorCredentials;

    public string $provider = '';

    public string $apiKey = '';

    public bool $clearApiKey = false;

    public bool $confirmRemove = false;

    public function mount(string $provider): void
    {
        if (! AiProviderCatalog::isSupported($provider)) {
            abort(404);
        }

        $this->provider = $provider;
        $this->apiKey = '';
    }

    public function dehydrate(): void
    {
        $this->apiKey = '';
        $this->clearApiKey = false;
    }

    public function saveConfiguration(): void
    {
        $user = $this->credentialManager();
        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap($this->provider);

        try {
            $this->credentialService()->save($integration, [
                'api_key' => $this->apiKey,
                'clear_api_key' => $this->clearApiKey,
            ], $user);
        } catch (ValidationException $exception) {
            $this->addError('apiKey', (string) ($exception->errors()['api_key'][0] ?? 'API key is required.'));

            return;
        }

        $this->apiKey = '';
        $this->clearApiKey = false;
        DemoState::flash(AiProviderCatalog::label($this->provider).' API key saved.', 'info');
    }

    public function testConfiguration(): void
    {
        $this->credentialManager();
        $integration = $this->integration();

        if ($integration === null || ! $this->resolver()->isConfigured($integration)) {
            DemoState::flash('Configure the '.AiProviderCatalog::label($this->provider).' API key first.', 'info');

            return;
        }

        $result = $this->connectionService()->testConnection($integration);
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

    public function removeConfiguration(): void
    {
        $user = $this->credentialManager();
        $this->confirmRemove = false;
        $integration = $this->integration();

        if ($integration === null) {
            DemoState::flash('No '.AiProviderCatalog::label($this->provider).' API key is stored.', 'info');

            return;
        }

        $this->credentialService()->remove($integration, $user);
        $this->apiKey = '';
        DemoState::flash(AiProviderCatalog::label($this->provider).' API key removed.', 'info');
    }

    public function render(): View
    {
        $integration = $this->integration();
        $configured = $integration instanceof CoreIntegration && $this->resolver()->isConfigured($integration);
        $keyConfigured = $integration instanceof CoreIntegration && $this->resolver()->hasDatabaseApiKey($integration);
        $config = is_array($integration?->config) ? $integration->config : [];
        $label = AiProviderCatalog::label($this->provider);

        return view('livewire.demo.integrations.ai-provider-integration', [
            'flash' => DemoState::pullFlash(),
            'canManageCredentials' => $this->canManageCredentials(),
            'providerLabel' => $label,
            'configured' => $configured,
            'statusLabel' => $configured ? 'Configured' : 'Not configured',
            'keyConfigured' => $keyConfigured,
            'connectionStatus' => is_string($config['connection_status'] ?? null) ? $config['connection_status'] : null,
            'lastTestedAt' => is_string($config['last_tested_at'] ?? null) ? $config['last_tested_at'] : null,
            'supportNote' => $this->supportNote(),
        ])->title($label);
    }

    private function integration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', $this->provider)
            ->first();
    }

    private function supportNote(): string
    {
        return match ($this->provider) {
            ProviderRegistry::GEMINI => 'Gemini uses a provider API key. This is separate from Google OAuth application credentials.',
            default => 'API keys are stored with authenticated application encryption. A stored key is not a live connection.',
        };
    }

    private function resolver(): OpenAiCredentialResolver|AnthropicCredentialResolver|GeminiCredentialResolver
    {
        return match ($this->provider) {
            ProviderRegistry::ANTHROPIC => app(AnthropicCredentialResolver::class),
            ProviderRegistry::GEMINI => app(GeminiCredentialResolver::class),
            default => app(OpenAiCredentialResolver::class),
        };
    }

    private function credentialService(): OpenAiProviderCredentialService|AnthropicProviderCredentialService|GeminiProviderCredentialService
    {
        return match ($this->provider) {
            ProviderRegistry::ANTHROPIC => app(AnthropicProviderCredentialService::class),
            ProviderRegistry::GEMINI => app(GeminiProviderCredentialService::class),
            default => app(OpenAiProviderCredentialService::class),
        };
    }

    private function connectionService(): OpenAiConnectionService|AnthropicConnectionService|GeminiConnectionService
    {
        return match ($this->provider) {
            ProviderRegistry::ANTHROPIC => app(AnthropicConnectionService::class),
            ProviderRegistry::GEMINI => app(GeminiConnectionService::class),
            default => app(OpenAiConnectionService::class),
        };
    }
}
