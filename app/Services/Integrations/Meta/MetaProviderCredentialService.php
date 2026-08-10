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
 * Admin-managed Meta provider credentials (read-only access token).
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
     *     access_token?: string|null,
     *     clear_access_token?: bool
     * }  $input
     */
    public function save(CoreIntegration $integration, array $input, User $user): CoreIntegrationCredential
    {
        $this->assertAdmin($user);
        $this->assertMeta($integration);

        $existing = $this->resolver->providerPayload($integration);
        $clear = (bool) ($input['clear_access_token'] ?? false);
        $tokenInput = isset($input['access_token']) && is_string($input['access_token'])
            ? trim($input['access_token'])
            : '';

        if ($clear) {
            $existingCredential = $integration->providerCredential()->first();
            if ($existingCredential instanceof CoreIntegrationCredential) {
                $existingCredential->delete();
            }

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
            $integration->forceFill([
                'config' => $config,
                'last_error' => null,
            ])->save();

            return new CoreIntegrationCredential([
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
                'encrypted_payload' => [],
            ]);
        }

        if ($tokenInput !== '') {
            $token = $tokenInput;
        } else {
            $token = (string) ($existing['access_token'] ?? '');
        }

        if ($token === '') {
            throw ValidationException::withMessages([
                'access_token' => 'Access token is required (leave blank to keep the stored value).',
            ]);
        }

        /** @var CoreIntegrationCredential $credential */
        $credential = CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            ],
            [
                'encrypted_payload' => [
                    'access_token' => $token,
                ],
                'expires_at' => null,
                'refreshed_at' => null,
            ],
        );

        return $credential;
    }

    public function remove(CoreIntegration $integration, User $user): void
    {
        $this->assertAdmin($user);
        $this->assertMeta($integration);

        $integration->providerCredential()->delete();

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
