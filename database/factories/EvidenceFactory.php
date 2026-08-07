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

    /**
     * Normalized SSL certificate evidence payload (no secrets / raw dumps).
     */
    public function sslCertificate(?array $payloadOverrides = null): static
    {
        $observedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return $this->state(fn (): array => [
            'type' => 'ssl_certificate',
            'title' => 'SSL certificate',
            'payload' => array_merge([
                'subject_common_name' => 'example.com',
                'issuer_common_name' => 'Example CA',
                'valid_from' => now()->subYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'valid_to' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'observed_at' => $observedAt,
                'fetch_method' => 'php_stream',
                'host' => 'example.com',
                'present' => true,
            ], $payloadOverrides ?? []),
        ]);
    }
}
