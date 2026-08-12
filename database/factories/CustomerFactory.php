<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Support\Options\IndustryOptions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => fake()->randomElement(CustomerType::cases()),
            'legal_name' => fake()->optional()->company(),
            'status' => CustomerStatus::Active,
            'industry' => fake()->optional()->randomElement(array_keys(IndustryOptions::options())),
            'hq_country' => fake()->optional()->randomElement(['TR', 'DE', 'GB', 'US']),
            'hq_city' => fake()->optional()->city(),
            'primary_email' => fake()->optional()->companyEmail(),
            'primary_phone' => fake()->optional()->e164PhoneNumber(),
            'service_started_at' => fake()->optional()->date(),
            'services_received' => fake()->optional()->sentence(),
            'services' => null,
        ];
    }
}
