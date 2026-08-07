<?php

namespace Database\Factories;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoreConnectionCredential>
 */
class CoreConnectionCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => CoreConnection::factory(),
            'encrypted_payload' => [
                'token' => fake()->sha256(),
                'refresh_token' => fake()->sha256(),
            ],
        ];
    }
}
