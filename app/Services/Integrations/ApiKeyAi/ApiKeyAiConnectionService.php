<?php

namespace App\Services\Integrations\ApiKeyAi;

use App\Models\CoreIntegration;
use App\Support\Ai\AiProviderCatalog;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Non-generative connection test for Groq / OpenRouter. Lists models with the stored key;
 * the key is only sent as a Bearer header.
 */
class ApiKeyAiConnectionService
{
    /**
     * Authenticated endpoints tried in order (a 404 moves on to the next one).
     *
     * @var array<string, list<string>>
     */
    private const array CHECK_URLS = [
        AiProviderCatalog::GROQ => ['https://api.groq.com/openai/v1/models'],
        AiProviderCatalog::OPENROUTER => ['https://openrouter.ai/api/v1/key', 'https://openrouter.ai/api/v1/auth/key'],
    ];

    public function __construct(private readonly ApiKeyAiCredentialResolver $resolver) {}

    /** @return array{ok: bool, message: string} */
    public function testConnection(CoreIntegration $integration): array
    {
        $provider = (string) $integration->provider;
        $urls = self::CHECK_URLS[$provider] ?? null;
        if ($urls === null) {
            throw new RuntimeException('Integration is not a supported API-key AI provider.');
        }
        $label = AiProviderCatalog::label($provider);
        $apiKey = $this->resolver->apiKey($integration);
        if ($apiKey === null) {
            return $this->fail($integration, 'Configure the '.$label.' API key first.');
        }

        $response = null;
        foreach ($urls as $url) {
            try {
                $response = Http::withToken($apiKey)->acceptJson()->timeout(20)->get($url);
            } catch (Throwable) {
                return $this->fail($integration, $label.' connection transport error.');
            }
            if ($response->status() !== 404) {
                break;
            }
        }
        if (in_array($response->status(), [401, 403], true)) {
            return $this->fail($integration, $label.' rejected the API key (authentication failed).', $response->status());
        }
        if (! $response->successful()) {
            return $this->fail($integration, $label.' connection issue (HTTP '.$response->status().').', $response->status());
        }

        $models = $response->json('data');
        $models = is_array($models) && array_is_list($models) ? $models : [];
        $config = is_array($integration->config) ? $integration->config : [];
        $config['connection_status'] = 'connected';
        $config['last_tested_at'] = now()->toIso8601String();
        $config['last_provider_http_status'] = $response->status();
        $config['models_visible_count'] = is_array($models) ? count($models) : 0;
        $integration->forceFill(['config' => $config, 'last_success_at' => now(), 'last_error' => null])->save();

        return ['ok' => true, 'message' => 'Connected'];
    }

    /** @return array{ok: false, message: string} */
    private function fail(CoreIntegration $integration, string $message, ?int $status = null): array
    {
        $config = is_array($integration->config) ? $integration->config : [];
        $config['connection_status'] = 'issue';
        $config['last_tested_at'] = now()->toIso8601String();
        if ($status !== null) {
            $config['last_provider_http_status'] = $status;
        }
        unset($config['models_visible_count']);
        $integration->forceFill(['config' => $config, 'last_error' => $message])->save();

        return ['ok' => false, 'message' => $message];
    }
}
