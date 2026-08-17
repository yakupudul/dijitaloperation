<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin-managed Meta application credentials (App ID/Secret) and optional
 * legacy access token. OAuth tokens share the TYPE_PROVIDER row and must
 * be merged, never replaced wholesale.
 */
class MetaProviderCredentialService
{
    public function __construct(
        private readonly MetaCredentialResolver $resolver,
    ) {}

    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may configure Meta provider credentials.');
        }
    }

    /**
     * @param  array{
     *     app_id?: string|null,
     *     app_secret?: string|null,
     *     clear_app_secret?: bool,
     *     access_token?: string|null,
     *     clear_access_token?: bool
     * }  $input
     */
    public function save(CoreIntegration $integration, array $input, User $user): CoreIntegrationCredential
    {
        $this->assertAdmin($user);
        $this->assertMeta($integration);

        $existing = $this->resolver->providerPayload($integration);
        $this->resolver->assertNoSecretsInPublicConfig(
            is_array($integration->config) ? $integration->config : [],
        );

        $appId = isset($input['app_id']) && is_string($input['app_id'])
            ? trim($input['app_id'])
            : (string) ($existing['app_id'] ?? '');

        $clearAppSecret = (bool) ($input['clear_app_secret'] ?? false);
        $appSecretInput = isset($input['app_secret']) && is_string($input['app_secret'])
            ? trim($input['app_secret'])
            : '';

        if ($clearAppSecret) {
            $appSecret = '';
        } elseif ($appSecretInput !== '') {
            $appSecret = $appSecretInput;
        } else {
            $appSecret = (string) ($existing['app_secret'] ?? '');
        }

        $clearToken = (bool) ($input['clear_access_token'] ?? false);
        $tokenInput = isset($input['access_token']) && is_string($input['access_token'])
            ? trim($input['access_token'])
            : '';

        if ($clearToken) {
            $token = '';
        } elseif ($tokenInput !== '') {
            $token = $tokenInput;
        } else {
            $token = (string) ($existing['access_token'] ?? '');
        }

        $payload = $existing;
        unset($payload['client_secret'], $payload['meta_app_secret']);

        if ($appId !== '') {
            $payload['app_id'] = $appId;
        } else {
            unset($payload['app_id']);
        }

        if ($appSecret !== '') {
            $payload['app_secret'] = $appSecret;
        } else {
            unset($payload['app_secret']);
        }

        if ($token !== '') {
            $payload['access_token'] = $token;
        } else {
            unset($payload['access_token']);
        }

        $hasApplication = $appId !== '' || $appSecret !== '';
        $hasToken = $token !== '';

        if (! $hasApplication && ! $hasToken) {
            if ($clearToken || $clearAppSecret) {
                $integration->providerCredential()->delete();
                if ($clearToken) {
                    $this->clearAuthorizationMetadata($integration);
                }

                return new CoreIntegrationCredential([
                    'integration_id' => $integration->id,
                    'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
                    'encrypted_payload' => [],
                ]);
            }

            throw ValidationException::withMessages([
                'app_id' => 'Enter Meta App ID and App Secret, or use Remove provider configuration.',
            ]);
        }

        $existingCredential = $integration->providerCredential()->first();

        /** @var CoreIntegrationCredential $credential */
        $credential = CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            ],
            [
                'encrypted_payload' => $payload,
                'expires_at' => $clearToken ? null : $existingCredential?->expires_at,
                'refreshed_at' => $clearToken ? null : $existingCredential?->refreshed_at,
            ],
        );

        if ($clearToken) {
            $this->clearAuthorizationMetadata($integration);
        }

        return $credential;
    }

    public function remove(CoreIntegration $integration, User $user): void
    {
        $this->assertAdmin($user);
        $this->assertMeta($integration);

        $integration->providerCredential()->delete();
        $this->clearAuthorizationMetadata($integration);
    }

    private function clearAuthorizationMetadata(CoreIntegration $integration): void
    {
        $config = is_array($integration->config) ? $integration->config : [];
        unset(
            $config['connection_status'],
            $config['last_tested_at'],
            $config['last_provider_http_status'],
            $config['meta_user_id'],
            $config['meta_user_name'],
            $config['last_resource_refresh_at'],
            $config['discovery_summary'],
        );
        $this->resolver->assertNoSecretsInPublicConfig($config);

        $integration->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Integration is not a Meta provider.');
        }
    }
}
