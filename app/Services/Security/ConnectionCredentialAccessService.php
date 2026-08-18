<?php

namespace App\Services\Security;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Support\Security\EphemeralSecret;
use InvalidArgumentException;

/**
 * Connection-scoped recoverable credential access (WordPress Application Password, legacy probes).
 * Prompt 64 — decrypt only for authorized server-side adapters.
 */
final class ConnectionCredentialAccessService
{
    public function wordpressApplicationPassword(CoreConnection $connection): ?EphemeralSecret
    {
        $payload = $this->payload($connection);
        $password = isset($payload['application_password']) && is_string($payload['application_password'])
            ? trim($payload['application_password'])
            : '';
        if ($password === '') {
            return null;
        }

        return new EphemeralSecret(
            value: $password,
            purpose: 'wordpress_application_password',
            provider: 'wordpress',
            connectionId: (int) $connection->id,
        );
    }

    public function wordpressUsername(CoreConnection $connection): ?string
    {
        $payload = $this->payload($connection);
        $username = isset($payload['username']) && is_string($payload['username'])
            ? trim($payload['username'])
            : '';

        return $username !== '' ? $username : null;
    }

    /**
     * Generic probe token for legacy connection types — purpose-tagged ephemeral only.
     */
    public function accessTokenForProbe(CoreConnection $connection): ?EphemeralSecret
    {
        $payload = $this->payload($connection);
        $token = isset($payload['access_token']) && is_string($payload['access_token'])
            ? trim($payload['access_token'])
            : '';
        if ($token === '') {
            return null;
        }

        return new EphemeralSecret(
            value: $token,
            purpose: 'connection_probe',
            provider: (string) $connection->type,
            connectionId: (int) $connection->id,
        );
    }

    /**
     * Presence / fingerprint metadata for UI — no secret material.
     *
     * @return array{has_application_password: bool, has_access_token: bool, has_api_key: bool}
     */
    public function status(CoreConnection $connection): array
    {
        $payload = $this->payload($connection);

        return [
            'has_application_password' => filled($payload['application_password'] ?? null),
            'has_access_token' => filled($payload['access_token'] ?? null),
            'has_api_key' => filled($payload['api_key'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CoreConnection $connection): array
    {
        $credential = $connection->relationLoaded('credential')
            ? $connection->credential
            : $connection->credential()->first();

        if (! $credential instanceof CoreConnectionCredential) {
            return [];
        }

        $payload = $credential->encrypted_payload;

        return is_array($payload) ? $payload : [];
    }

    public function denyBrowserReveal(): never
    {
        throw new InvalidArgumentException('PLAINTEXT_CREDENTIAL_VIEW_FORBIDDEN');
    }
}
