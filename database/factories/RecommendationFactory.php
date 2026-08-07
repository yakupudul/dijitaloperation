<?php

namespace Database\Factories;

use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'finding_id' => Finding::factory(),
            'digital_asset_id' => function (array $attributes): ?int {
                if (! isset($attributes['finding_id'])) {
                    return DigitalAsset::factory()->create()->id;
                }

                return Finding::query()->find($attributes['finding_id'])?->digital_asset_id;
            },
            'source_module' => fake()->randomElement(['website', 'search-console', 'pagespeed']),
            'title' => fake()->sentence(6),
            'action' => fake()->optional()->paragraph(),
            'rationale' => fake()->optional()->paragraph(),
            'priority' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'effort' => fake()->optional()->randomElement(['low', 'medium', 'high']),
            'status' => fake()->randomElement(['open', 'accepted', 'dismissed', 'converted']),
        ];
    }
}
