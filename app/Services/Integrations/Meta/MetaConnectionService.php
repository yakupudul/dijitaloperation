<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use RuntimeException;

/**
 * Non-mutating Meta health check via GET /me.
 */
class MetaConnectionService
{
    public function __construct(
        private readonly MetaCredentialResolver $resolver,
        private readonly MetaApiClient $client,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(CoreIntegration $integration): array
    {
        $this->assertMeta($integration);

        if (! $this->resolver->isConfigured($integration)) {
            $message = MetaOperatorMessages::forException(new MetaException(
                'Configuration incomplete.',
                kind: MetaException::KIND_CONFIG,
            ));
            $this->persistFailure($integration, $message);

            return ['ok' => false, 'message' => $message];
        }

        try {
            $me = $this->client->get($integration, 'me', [
                'fields' => 'id,name',
            ]);
            $id = isset($me['id']) && is_string($me['id']) ? $me['id'] : null;
            $name = isset($me['name']) && is_string($me['name']) ? $me['name'] : null;

            if ($id === null || $id === '') {
                $exception = new MetaException(
                    'Malformed provider response.',
                    kind: MetaException::KIND_PROVIDER,
                );
                $message = MetaOperatorMessages::forException($exception);
                $this->persistFailure($integration, $message);

                return ['ok' => false, 'message' => $message];
            }

            $this->persistSuccess($integration, $id, $name);

            return [
                'ok' => true,
                'message' => 'Meta authentication succeeded.'.($name ? ' Identity readable.' : ''),
            ];
        } catch (MetaException $exception) {
            $message = MetaOperatorMessages::forException($exception);
            $this->persistFailure($integration, $message, $exception->httpStatus);

            return ['ok' => false, 'message' => $message];
        }
    }

    private function persistSuccess(CoreIntegration $integration, ?string $userId, ?string $userName): void
    {
        $config = is_array($integration->config) ? $integration->config : [];
        $config['connection_status'] = 'connected';
        $config['last_tested_at'] = now()->toIso8601String();
        $config['last_provider_http_status'] = 200;
        if ($userId !== null) {
            $config['meta_user_id'] = $userId;
        }
        if ($userName !== null) {
            $config['meta_user_name'] = $userName;
        }

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

        $integration->forceFill([
            'config' => $config,
            'last_error' => $message,
        ])->save();
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Integration is not a Meta provider.');
        }
    }
}
