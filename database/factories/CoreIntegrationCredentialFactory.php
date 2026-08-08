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
            'encrypted_payload' => [
                'access_token' => 'sample-access-token',
                'refresh_token' => 'sample-refresh-token',
            ],
            'expires_at' => null,
            'refreshed_at' => null,
        ];
    }
}
