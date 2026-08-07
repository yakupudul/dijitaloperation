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
        $startedAt = fake()->dateTimeBetween('-14 days', 'now');
        $finishedAt = fake()->optional(0.8)->dateTimeBetween($startedAt, 'now');

        return [
            'digital_asset_id' => DigitalAsset::factory(),
            'core_connection_id' => null,
            'module_id' => fake()->randomElement(['website', 'search-console', 'pagespeed']),
            'status' => $finishedAt === null
                ? fake()->randomElement(['pending', 'running'])
                : fake()->randomElement(['completed', 'failed']),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => [
                'trigger' => fake()->randomElement(['manual', 'schedule']),
                'attempt' => fake()->numberBetween(1, 3),
            ],
        ];
    }
}
