<?php

namespace Database\Factories;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evidence>
 */
class EvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'run_id' => Run::factory(),
            'digital_asset_id' => DigitalAsset::factory(),
            'source_module' => 'website-diagnosis',
            'type' => 'http_fetch',
            'title' => 'HTTP fetch',
            'payload' => [
                'url' => $url,
                'status_code' => 200,
                'effective_url' => $url,
                'is_https' => str_starts_with($url, 'https://'),
                'response_is_ok' => true,
            ],
            'observed_at' => now(),
        ];
    }
}
