<?php

namespace Database\Factories;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoreIntegration>
 */
class CoreIntegrationFactory extends Factory
{
    protected $model = CoreIntegration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => ProviderRegistry::GOOGLE,
            'name' => ProviderRegistry::defaultName(ProviderRegistry::GOOGLE),
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [],
            'last_success_at' => null,
            'last_error' => null,
        ];
    }

    public function provider(string $provider): static
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
            'name' => ProviderRegistry::defaultName($provider),
        ]);
    }

    public function google(): static
    {
        return $this->provider(ProviderRegistry::GOOGLE);
    }

    public function meta(): static
    {
        return $this->provider(ProviderRegistry::META);
    }

    public function dataforseo(): static
    {
        return $this->provider(ProviderRegistry::DATAFORSEO);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'status' => CoreIntegration::STATUS_DISABLED,
        ]);
    }
}
