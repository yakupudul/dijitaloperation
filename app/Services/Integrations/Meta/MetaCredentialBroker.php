<?php

namespace App\Services\Integrations\Meta;

use App\Exceptions\Integrations\MetaException;
use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Security\EphemeralSecret;

/**
 * Sole application boundary for obtaining a usable Meta access token (Prompt 64).
 * Never serializes tokens into queues, UI, or logs.
 */
final class MetaCredentialBroker
{
    public function __construct(
        private readonly MetaCredentialResolver $credentials,
    ) {}

    public function accessTokenFor(CoreIntegration $integration): EphemeralSecret
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new MetaException(
                'Integration is not Meta.',
                kind: MetaException::KIND_CONFIG,
            );
        }

        $token = $this->credentials->accessToken($integration);
        if ($token === null || $token === '') {
            throw new MetaException(
                'Meta access token is not configured.',
                kind: MetaException::KIND_CONFIG,
            );
        }

        return new EphemeralSecret(
            value: $token,
            purpose: 'meta_provider_request',
            provider: ProviderRegistry::META,
            integrationId: (int) $integration->id,
        );
    }

    public function isConfigured(CoreIntegration $integration): bool
    {
        return $this->credentials->hasTenantAuthorization($integration);
    }
}
