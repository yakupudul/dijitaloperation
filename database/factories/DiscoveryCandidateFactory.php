<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoveryCandidate>
 */
class DiscoveryCandidateFactory extends Factory
{
    protected $model = DiscoveryCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->sentence(3);

        return [
            'brand_id' => Brand::factory(),
            'digital_asset_id' => function (array $attributes): int {
                if (isset($attributes['brand_id'])) {
                    return DigitalAsset::factory()->create([
                        'brand_id' => $attributes['brand_id'],
                        'type' => 'website',
                    ])->id;
                }

                return DigitalAsset::factory()->create(['type' => 'website'])->id;
            },
            'run_id' => null,
            'evidence_id' => null,
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'candidate_kind' => DiscoveryCandidate::KIND_FACT,
            'candidate_type' => 'service',
            'target_field' => 'products_services',
            'proposed_value' => $value,
            'support_json' => [
                'source_url' => 'https://example.com/services',
                'retrieved_at' => now()->toIso8601String(),
            ],
            'support_label' => 'strong',
            'status' => DiscoveryCandidate::STATUS_PENDING,
            'reviewed_by_id' => null,
            'reviewed_at' => null,
            'accepted_value' => null,
            'was_edited' => false,
        ];
    }
}
