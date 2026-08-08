<?php

namespace Database\Factories;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoreExternalResource>
 */
class CoreExternalResourceFactory extends Factory
{
    protected $model = CoreExternalResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_id' => CoreIntegration::factory()->google(),
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/'.fake()->numerify('########'),
            'display_name' => fake()->company().' GA4',
            'parent_external_id' => null,
            'metadata' => [],
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'discovered_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function searchConsole(): static
    {
        return $this->state(fn (): array => [
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:'.fake()->domainName(),
            'display_name' => 'Search Console property',
        ]);
    }
}
