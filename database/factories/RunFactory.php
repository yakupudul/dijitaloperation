<?php

namespace Database\Factories;

use App\Models\DigitalAsset;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'digital_asset_id' => DigitalAsset::factory(),
            'connection_id' => null,
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
            'meta' => [
                'source' => 'factory',
                'trigger' => fake()->randomElement(['manual', 'scheduled']),
            ],
        ];
    }
}
