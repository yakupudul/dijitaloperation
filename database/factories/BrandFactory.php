<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->company().' Brand',
            'sector' => fake()->optional()->randomElement(['Retail', 'SaaS', 'Healthcare', 'Finance']),
            'primary_country' => fake()->optional()->countryCode(),
            'target_markets' => fake()->optional()->randomElements(['US', 'TR', 'DE', 'UK', 'AE'], 2),
            'languages' => fake()->optional()->randomElements(['en', 'tr', 'de', 'ar'], 2),
            'description' => fake()->optional()->sentence(),
            'audience' => fake()->optional()->sentence(),
            'offerings' => fake()->optional()->sentence(),
            'competitors' => fake()->optional()->sentence(),
            'logo_url' => fake()->optional()->url(),
        ];
    }
}
