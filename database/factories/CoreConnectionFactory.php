<?php

namespace Database\Factories;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoreConnection>
 */
class CoreConnectionFactory extends Factory
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
            'type' => fake()->randomElement([
                'wordpress',
                'ga4',
                'search_console',
                'pagespeed',
                'dataforseo',
            ]),
            'name' => fake()->words(2, true),
            'config' => [
                'property_id' => fake()->uuid(),
            ],
            'enabled' => true,
            'last_success_at' => null,
            'last_error' => null,
        ];
    }
}
