<?php

namespace App\Services\Integrations\Anthropic;

use App\Models\CoreIntegration;
use App\Support\Ai\AiProviderCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Non-generative Anthropic Test connection via GET /v1/models.
 */
class AnthropicConnectionService
{
    public function __construct(
        private readonly AnthropicCredentialResolver $resolver,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(CoreIntegration $integration): array
    {
        $this->assertProvider($integration);

        if (! $this->resolver->isConfigured($integration)) {
            $message = 'Configure the Anthropic API key first.';
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        }

        $apiKey = $this->resolver->apiKey($integration);
        $baseUrl = rtrim((string) config('moxdop.anthropic.base_url', 'https://api.anthropic.com/v1'), '/');
        $timeout = (int) config('moxdop.anthropic.timeout', 20);
        $version = (string) config('moxdop.anthropic.version', '2023-06-01');

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) $apiKey,
                'anthropic-version' => $version,
            ])
                ->acceptJson()
                ->timeout($timeout)
                ->get($baseUrl.'/models');

            if ($response->status() === 401 || $response->status() === 403) {
                $message = 'Anthropic rejected the API key (authentication failed).';
                $this->persistFailure($integration, $message, $response->status());

                return ['ok' => false, 'message' => $message];
            }

            if (! $response->successful()) {
                $message = 'Anthropic connection issue (HTTP '.$response->status().').';
                $this->persistFailure($integration, $message, $response->status());

                return ['ok' => false, 'message' => $message];
            }

            $data = $response->json('data');
            $count = is_array($data) ? count($data) : 0;
            $this->persistSuccess($integration, $response->status(), $count);

            return [
                'ok' => true,
                'message' => 'Connected',
            ];
        } catch (RequestException $exception) {
            $status = $exception->response?->status();
            $message = $status !== null
                ? 'Anthropic connection issue (HTTP '.$status.').'
                : 'Anthropic connection transport error.';
            $this->persistFailure($integration, $message, $status);

            return ['ok' => false, 'message' => $message];
        } catch (Throwable) {
            $message = 'Anthropic connection transport error.';
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

    private function assertProvider(CoreIntegration $integration): void
    {
        if ($integration->provider !== AiProviderCatalog::ANTHROPIC) {
            throw new RuntimeException('Integration is not an Anthropic provider.');
        }
    }
}
