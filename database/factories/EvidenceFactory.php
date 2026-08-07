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
     * @return $this
     */
    public function tlsInfo(): static
    {
        $host = fake()->domainName();

        return $this->state(fn (): array => [
            'type' => 'tls_info',
            'title' => 'TLS certificate info',
            'payload' => [
                'subject_common_name' => $host,
                'issuer_common_name' => 'Example CA',
                'valid_from' => now()->subYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'valid_to' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'fetch_method' => 'php_stream',
                'host' => strtolower($host),
                'present' => true,
            ],
        ]);
    }

    /**
     * @return $this
     */
    public function redirects(): static
    {
        $host = strtolower(fake()->domainName());
        $startUrl = 'http://'.$host;
        $finalUrl = 'https://'.$host.'/';

        return $this->state(fn (): array => [
            'type' => 'redirects',
            'title' => 'HTTP redirect chain',
            'payload' => [
                'start_url' => $startUrl,
                'final_url' => $finalUrl,
                'hop_count' => 1,
                'hops' => [
                    [
                        'url' => $startUrl,
                        'status' => 301,
                        'location' => $finalUrl,
                    ],
                ],
                'upgraded_to_https_same_host' => true,
                'error_class' => null,
            ],
        ]);
    }
}
