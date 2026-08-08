<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin-managed Google provider/application credentials (not OAuth tokens).
 */
class GoogleProviderCredentialService
{
    public function __construct(
        private readonly GoogleCredentialResolver $resolver,
    ) {}

    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may configure Google provider credentials.');
        }
    }

    /**
     * @param  array{
     *     client_id?: string|null,
     *     client_secret?: string|null,
     *     developer_token?: string|null,
     *     clear_client_secret?: bool,
     *     clear_developer_token?: bool
     * }  $input
     */
    public function save(CoreIntegration $integration, array $input, User $user): CoreIntegrationCredential
    {
        $this->assertAdmin($user);
        $this->assertGoogle($integration);

        $existing = $this->resolver->providerPayload($integration);

        $clientId = isset($input['client_id']) && is_string($input['client_id'])
            ? trim($input['client_id'])
            : (string) ($existing['client_id'] ?? '');

        $clearSecret = (bool) ($input['clear_client_secret'] ?? false);
        $clearDeveloperToken = (bool) ($input['clear_developer_token'] ?? false);

        $clientSecretInput = isset($input['client_secret']) && is_string($input['client_secret'])
            ? trim($input['client_secret'])
            : '';
        $developerTokenInput = isset($input['developer_token']) && is_string($input['developer_token'])
            ? trim($input['developer_token'])
            : '';

        if ($clearSecret) {
            $clientSecret = '';
        } elseif ($clientSecretInput !== '') {
            $clientSecret = $clientSecretInput;
        } else {
            $clientSecret = (string) ($existing['client_secret'] ?? '');
        }

        if ($clearDeveloperToken) {
            $developerToken = '';
        } elseif ($developerTokenInput !== '') {
            $developerToken = $developerTokenInput;
        } else {
            $developerToken = (string) ($existing['developer_token'] ?? '');
        }

        if ($clientId === '' && $clientSecret === '' && $developerToken === '') {
            throw ValidationException::withMessages([
                'client_id' => 'Enter at least one application credential, or use Remove provider configuration.',
            ]);
        }

        $payload = array_filter([
            'client_id' => $clientId !== '' ? $clientId : null,
            'client_secret' => $clientSecret !== '' ? $clientSecret : null,
            'developer_token' => $developerToken !== '' ? $developerToken : null,
        ], fn (mixed $value): bool => $value !== null);

        /** @var CoreIntegrationCredential $credential */
        $credential = CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            ],
            [
                'encrypted_payload' => $payload,
                'expires_at' => null,
                'refreshed_at' => null,
            ],
        );

        return $credential;
    }

    public function remove(CoreIntegration $integration, User $user): void
    {
        $this->assertAdmin($user);
        $this->assertGoogle($integration);

        $integration->providerCredential()->delete();
    }

    private function assertGoogle(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            throw new RuntimeException('Integration is not a Google provider.');
        }
    }
}
