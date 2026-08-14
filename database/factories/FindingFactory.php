<?php

namespace Database\Factories;

use App\Models\DigitalAsset;
use App\Models\Finding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seenAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'digital_asset_id' => DigitalAsset::factory(),
            'source_module' => fake()->randomElement(['website', 'search-console', 'pagespeed']),
            'fingerprint' => fake()->unique()->sha1(),
            'category' => fake()->randomElement(['performance', 'seo', 'security', 'availability']),
            'severity' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'title' => fake()->sentence(6),
            'summary' => fake()->optional()->paragraph(),
            'confidence' => fake()->randomFloat(4, 0, 1),
            'status' => fake()->randomElement(['open', 'acknowledged', 'resolved']),
            'origin' => 'legacy_unverified',
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
            'last_run_id' => null,
        ];
    }
}
