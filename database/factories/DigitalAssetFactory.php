<?php

namespace Database\Factories;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigitalAsset>
 */
class DigitalAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement([
                'website',
                'google_business_profile',
                'google_ads',
                'meta_ads',
                'instagram',
                'youtube',
                'crm',
            ]),
            'status' => DigitalAssetStatus::Active,
            'module_id' => fake()->optional()->slug(2),
        ];
    }
}
