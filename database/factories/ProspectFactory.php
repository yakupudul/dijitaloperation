<?php

namespace Database\Factories;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'website_url' => null,
            'source' => fake()->randomElement(ProspectSource::cases()),
            'inquiry' => fake()->optional()->sentence(),
            'contact_name' => fake()->optional()->name(),
            'contact_email' => fake()->optional()->safeEmail(),
            'contact_phone' => fake()->optional()->phoneNumber(),
            'country' => 'TR',
            'city' => fake()->optional()->city(),
            'identity_status' => ProspectIdentityStatus::Unknown,
            'status' => ProspectStatus::New,
            'owner_user_id' => null,
        ];
    }

    public function withOwner(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'owner_user_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function withWebsite(string $url = 'http://1.1.1.1'): static
    {
        return $this->state(fn (): array => [
            'website_url' => $url,
            'identity_status' => ProspectIdentityStatus::Partial,
        ]);
    }
}
