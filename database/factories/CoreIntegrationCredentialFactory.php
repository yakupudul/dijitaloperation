<?php

namespace Database\Factories;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoreIntegrationCredential>
 */
class CoreIntegrationCredentialFactory extends Factory
{
    protected $model = CoreIntegrationCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_id' => CoreIntegration::factory(),
            'credential_type' => CoreIntegrationCredential::TYPE_AUTHORIZATION,
            'encrypted_payload' => [
                'access_token' => 'sample-access-token',
                'refresh_token' => 'sample-refresh-token',
            ],
            'expires_at' => null,
            'refreshed_at' => null,
        ];
    }

    public function authorization(): static
    {
        return $this->state(fn (): array => [
            'credential_type' => CoreIntegrationCredential::TYPE_AUTHORIZATION,
            'encrypted_payload' => [
                'access_token' => 'sample-access-token',
                'refresh_token' => 'sample-refresh-token',
            ],
        ]);
    }

    public function provider(): static
    {
        return $this->state(fn (): array => [
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => [
                'client_id' => 'sample-client-id',
                'client_secret' => 'sample-client-secret',
                'developer_token' => 'sample-developer-token',
            ],
            'expires_at' => null,
            'refreshed_at' => null,
        ]);
    }
}
