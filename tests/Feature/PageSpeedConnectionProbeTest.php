<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\PageSpeedConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PageSpeedConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_lab_probe_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed*' => Http::response([
                'id' => 'https://example.com/',
                'lighthouseResult' => [
                    'requestedUrl' => 'https://example.com/',
                    'finalUrl' => 'https://example.com/',
                    'fetchTime' => '2026-08-07T14:00:00.000Z',
                    'categories' => [
                        'performance' => [
                            'score' => 0.92,
                        ],
                    ],
                    'audits' => [
                        'largest-contentful-paint' => ['numericValue' => 1800.5],
                        'cumulative-layout-shift' => ['numericValue' => 0.04],
                        'first-contentful-paint' => ['numericValue' => 1200],
                        'total-blocking-time' => ['numericValue' => 80],
                        'speed-index' => ['numericValue' => 2100],
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://example.com',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => PageSpeedConnectionProbeService::CONNECTION_TYPE,
            'name' => 'PageSpeed Example',
            'config' => ['strategy' => 'mobile'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'api_key' => 'test-pagespeed-api-key-secret',
            ],
        ]);

        $run = app(PageSpeedConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame(PageSpeedConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', PageSpeedConnectionProbeService::EVIDENCE_TYPE_PAGESPEED_LAB)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('https://example.com', $evidence->payload['requested_url']);
        $this->assertSame('https://example.com/', $evidence->payload['final_url']);
        $this->assertSame('mobile', $evidence->payload['strategy']);
        $this->assertSame(92, $evidence->payload['performance_score']);
        $this->assertSame(1800.5, $evidence->payload['lcp_ms']);
        $this->assertSame(0.04, $evidence->payload['cls']);
        $this->assertTrue($evidence->payload['lab_data']);
        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('test-pagespeed-api-key-secret', (string) $encoded);

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed')
                && str_contains($request->url(), 'url=')
                && str_contains($request->url(), 'strategy=mobile')
                && str_contains($request->url(), 'category=performance')
                && str_contains($request->url(), 'key=');
        });
    }

    public function test_missing_performance_metrics_sets_last_error(): void
    {
        Http::fake([
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed*' => Http::response([
                'lighthouseResult' => [
                    'finalUrl' => 'https://missing.example/',
                    'categories' => [],
                    'audits' => [],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'pagespeed',
            'config' => [],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['api_key' => 'key-xyz'],
        ]);

        $run = app(PageSpeedConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('performance_metrics_missing', $evidence->payload['status_or_error']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertSame('performance_metrics_missing', $connection->last_error);
    }

    public function test_connection_failure_records_error_class(): void
    {
        Http::fake(function () {
            throw new ConnectionException('DNS failed');
        });

        $asset = DigitalAsset::factory()->create(['type' => 'website', 'primary_url' => 'https://ex.com']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'pagespeed',
            'config' => [],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['api_key' => 'token'],
        ]);

        $run = app(PageSpeedConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);
        $connection->refresh();
        $this->assertStringContainsString('connection', (string) $connection->last_error);
    }

    public function test_rejects_missing_api_key(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website', 'primary_url' => 'https://ex.com']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'pagespeed',
            'enabled' => true,
            'config' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(PageSpeedConnectionProbeService::class)->probe($connection);
    }

    public function test_rejects_missing_url(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => null,
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'pagespeed',
            'enabled' => true,
            'config' => [],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['api_key' => 'key'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(PageSpeedConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_rejects_invalid_strategy(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ex.com',
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'pagespeed',
            'enabled' => true,
            'config' => ['strategy' => 'tablet'],
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['api_key' => 'key'],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(PageSpeedConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed*' => Http::response([
                'lighthouseResult' => [
                    'finalUrl' => 'https://ro.example/',
                    'categories' => [
                        'performance' => ['score' => 0.5],
                    ],
                    'audits' => [
                        'largest-contentful-paint' => ['numericValue' => 3000],
                        'cumulative-layout-shift' => ['numericValue' => 0.1],
                        'first-contentful-paint' => ['numericValue' => 2000],
                        'total-blocking-time' => ['numericValue' => 200],
                        'speed-index' => ['numericValue' => 3500],
                    ],
                ],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ro.example',
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'pagespeed',
            'config' => ['url' => 'https://ro.example/'],
            'enabled' => true,
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => ['api_key' => 'ro-key'],
        ]);

        app(PageSpeedConnectionProbeService::class)->probe(
            $connection->fresh(['credential', 'digitalAsset']),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
