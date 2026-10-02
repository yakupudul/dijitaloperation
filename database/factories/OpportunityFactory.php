<?php

namespace Database\Factories;

use App\Models\DigitalAsset;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $detectedAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'digital_asset_id' => DigitalAsset::factory(),
            'customer_id' => null,
            'brand_id' => null,
            'origin' => 'legacy_unverified',
            'rule_id' => 'website:gsc:organic-click-recovery',
            'rule_version' => 1,
            'fingerprint' => fake()->unique()->sha256(),
            'semantic_fingerprint' => fake()->unique()->sha256(),
            'subject_kind' => 'digital_asset',
            'subject_id' => null,
            'category' => fake()->randomElement(['visibility', 'growth']),
            'status' => Opportunity::STATUS_OPEN,
            'detection_state' => 'detected',
            'qualitative_priority' => 'medium',
            'service_definition_code' => 'seo',
            'commercial_scope_state' => 'outside_current_scope',
            'title' => fake()->sentence(6),
            'description' => fake()->optional()->paragraph(),
            'first_detected_at' => $detectedAt,
            'last_detected_at' => $detectedAt,
            'closed_at' => null,
            'latest_evaluation_id' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Opportunity $opportunity): void {
            $asset = $opportunity->digital_asset_id !== null
                ? DigitalAsset::query()->with('brand')->find($opportunity->digital_asset_id)
                : null;
            if ($asset === null) {
                return;
            }
            $opportunity->customer_id ??= $asset->brand?->customer_id;
            $opportunity->brand_id ??= $asset->brand_id;
            $opportunity->subject_id ??= (string) $asset->id;
        })->afterCreating(function (Opportunity $opportunity): void {
            $asset = $opportunity->digitalAsset()->with('brand')->first();
            if ($asset === null) {
                return;
            }
            $opportunity->forceFill([
                'customer_id' => $opportunity->customer_id ?? $asset->brand?->customer_id,
                'brand_id' => $opportunity->brand_id ?? $asset->brand_id,
                'subject_id' => $opportunity->subject_id ?? (string) $asset->id,
            ])->save();
        });
    }
}
