<?php

namespace App\Services\Integrations\DataForSeo;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin-managed DataForSEO provider credentials (API login + API password).
 */
class DataForSeoProviderCredentialService
{
    public function __construct(
        private readonly DataForSeoCredentialResolver $resolver,
    ) {}

    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may configure DataForSEO provider credentials.');
        }
    }

    /**
     * @param  array{
     *     login?: string|null,
     *     password?: string|null,
     *     clear_password?: bool
     * }  $input
     */
    public function save(CoreIntegration $integration, array $input, User $user): CoreIntegrationCredential
    {
        $this->assertAdmin($user);
        $this->assertDataForSeo($integration);

        $existing = $this->resolver->providerPayload($integration);

        $login = isset($input['login']) && is_string($input['login'])
            ? trim($input['login'])
            : (string) ($existing['login'] ?? '');

        $clearPassword = (bool) ($input['clear_password'] ?? false);
        $passwordInput = isset($input['password']) && is_string($input['password'])
            ? trim($input['password'])
            : '';

        if ($clearPassword) {
            $password = '';
        } elseif ($passwordInput !== '') {
            $password = $passwordInput;
        } else {
            $password = (string) ($existing['password'] ?? '');
        }

        if ($login === '' && $password === '') {
            throw ValidationException::withMessages([
                'login' => 'Enter API Login and API Password, or use Remove provider configuration.',
            ]);
        }

        // Explicit password clear may leave login-only incomplete state (not configured for API calls).
        if ($password === '' && ! $clearPassword) {
            throw ValidationException::withMessages([
                'password' => 'API Password is required (or enable Clear stored API Password).',
            ]);
        }

        if ($login === '') {
            throw ValidationException::withMessages([
                'login' => 'API Login is required for DataForSEO.',
            ]);
        }

        $payload = array_filter([
            'login' => $login !== '' ? $login : null,
            'password' => $password !== '' ? $password : null,
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
        $this->assertDataForSeo($integration);

        $integration->providerCredential()->delete();

        $config = is_array($integration->config) ? $integration->config : [];
        unset(
            $config['connection_status'],
            $config['account_login'],
            $config['timezone'],
            $config['balance'],
            $config['balance_checked_at'],
            $config['last_tested_at'],
        );

        $integration->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();
    }

    private function assertDataForSeo(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::DATAFORSEO) {
            throw new RuntimeException('Integration is not a DataForSEO provider.');
        }
    }
}
