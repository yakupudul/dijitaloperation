<?php

namespace App\Services\Integrations\OpenAi;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Agency-level OpenAI Test connection via non-generative GET /v1/models.
 */
class OpenAiConnectionService
{
    public function __construct(
        private readonly OpenAiCredentialResolver $resolver,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(CoreIntegration $integration): array
    {
        $this->assertOpenAi($integration);

        if (! $this->resolver->isConfigured($integration)) {
            $message = 'Configure the OpenAI API key first.';
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        }

        $apiKey = $this->resolver->apiKey($integration);
        $baseUrl = rtrim((string) config('moxdop.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = (int) config('moxdop.openai.timeout', 20);

        try {
            $response = Http::withToken((string) $apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->get($baseUrl.'/models');

            if ($response->status() === 401 || $response->status() === 403) {
                $message = 'OpenAI rejected the API key (authentication failed).';
                $this->persistFailure($integration, $message, $response->status());

                return ['ok' => false, 'message' => $message];
            }

            if (! $response->successful()) {
                $message = 'OpenAI connection issue (HTTP '.$response->status().').';
                $this->persistFailure($integration, $message, $response->status());

                return ['ok' => false, 'message' => $message];
            }

            $data = $response->json('data');
            $count = is_array($data) ? count($data) : 0;
            $this->persistSuccess($integration, $response->status(), $count);

            return [
                'ok' => true,
                'message' => 'OpenAI authentication succeeded.'.($count > 0 ? ' Models endpoint reachable.' : ''),
            ];
        } catch (RequestException $exception) {
            $status = $exception->response?->status();
            $message = $status !== null
                ? 'OpenAI connection issue (HTTP '.$status.').'
                : 'OpenAI connection transport error.';
            $this->persistFailure($integration, $message, $status);

            return ['ok' => false, 'message' => $message];
        } catch (Throwable) {
            $message = 'OpenAI connection transport error.';
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        }
    }

    private function persistSuccess(CoreIntegration $integration, int $httpStatus, int $modelsVisibleCount): void
    {
        $config = is_array($integration->config) ? $integration->config : [];
        $now = now()->toIso8601String();

        $config['connection_status'] = 'connected';
        $config['last_tested_at'] = $now;
        $config['last_provider_http_status'] = $httpStatus;
        // Persist only a count — never the full model list payload.
        $config['models_visible_count'] = $modelsVisibleCount;

        $integration->forceFill([
            'config' => $config,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function persistFailure(CoreIntegration $integration, string $message, ?int $httpStatus = null): void
    {
        $config = is_array($integration->config) ? $integration->config : [];
        $config['connection_status'] = 'issue';
        $config['last_tested_at'] = now()->toIso8601String();
        if ($httpStatus !== null) {
            $config['last_provider_http_status'] = $httpStatus;
        }
        unset($config['models_visible_count']);

        $integration->forceFill([
            'config' => $config,
            'last_error' => $message,
        ])->save();
    }

    private function assertOpenAi(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::OPENAI) {
            throw new RuntimeException('Integration is not an OpenAI provider.');
        }
    }
}
