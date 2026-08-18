<?php

namespace Database\Factories;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
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
            'source_kind' => RecommendationSourceKind::Finding->value,
            'finding_id' => Finding::factory(),
            'opportunity_id' => null,
            'origin' => RecommendationOrigin::Legacy->value,
            'idempotency_key' => null,
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
            'status' => fake()->randomElement(Recommendation::STATUSES),
        ];
    }

    /**
     * Opportunity-sourced Recommendation — no placeholder Finding is created.
     */
    public function forOpportunity(Opportunity $opportunity): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_kind' => RecommendationSourceKind::Opportunity->value,
            'finding_id' => null,
            'opportunity_id' => $opportunity->id,
            'digital_asset_id' => $opportunity->digital_asset_id,
            'source_module' => 'operations',
            'origin' => RecommendationOrigin::Operator->value,
        ]);
    }

    public function forFinding(Finding $finding): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_kind' => RecommendationSourceKind::Finding->value,
            'finding_id' => $finding->id,
            'opportunity_id' => null,
            'digital_asset_id' => $finding->digital_asset_id,
        ]);
    }
}
