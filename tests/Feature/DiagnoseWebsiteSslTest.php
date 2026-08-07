<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Services\WebsiteDiagnosisService;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DiagnoseWebsiteSslTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_certificate_creates_tls_info_evidence_without_finding(): void
    {
        Http::fake([
            'https://secure.example' => Http::response('ok', 200),
            'http://secure.example' => Http::response('', 301, ['Location' => 'https://secure.example/']),
        ]);

        $this->travelTo(new DateTimeImmutable('2026-08-07T12:00:00Z'));

        $host = 'secure.example';

        $this->mockSslProbe([
            'subject_common_name' => $host,
            'issuer_common_name' => 'Example CA',
            'valid_from' => '2025-08-07T12:00:00Z',
            'valid_to' => '2027-08-07T12:00:00Z',
            'observed_at' => '2026-08-07T12:00:00Z',
            'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
            'host' => $host,
            'present' => true,
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://secure.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_TLS_INFO)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertSame($host, $evidence->payload['host']);
        $this->assertTrue($evidence->payload['present']);
        $this->assertSame('2027-08-07T12:00:00Z', $evidence->payload['valid_to']);
        $this->assertSame(0, Finding::query()->where('category', 'transport')->count());
        $this->assertContains(
            WebsiteDiagnosisService::CATALOG_HTTPS_TLS_VALIDITY,
            $run->metadata['checks'] ?? [],
        );
    }

    public function test_expired_certificate_upserts_transport_finding_with_catalog_fingerprint(): void
    {
        Http::fake([
            'https://expired.example' => Http::response('ok', 200),
            'http://expired.example' => Http::response('', 301, ['Location' => 'https://expired.example/']),
        ]);

        $this->travelTo(new DateTimeImmutable('2026-08-07T12:00:00Z'));

        $host = 'expired.example';

        $this->mockSslProbe([
            'subject_common_name' => $host,
            'issuer_common_name' => 'Example CA',
            'valid_from' => '2024-01-01T00:00:00Z',
            'valid_to' => '2025-01-01T00:00:00Z',
            'observed_at' => '2026-08-07T12:00:00Z',
            'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
            'host' => $host,
            'present' => true,
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://expired.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);
        $firstRun = $service->diagnose($asset);

        $expectedFingerprint = hash('sha256', 'https-tls-validity|host=expired.example');

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->where('category', 'transport')->first();
        $this->assertNotNull($finding);
        $this->assertSame('HTTPS/TLS certificate problem', $finding->title);
        $this->assertSame('high', $finding->severity);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
        $this->assertSame($firstRun->id, $finding->last_run_id);
        $this->assertStringContainsString('expired', (string) $finding->summary);

        $this->travel(5)->minutes();

        $secondRun = $service->diagnose($asset->fresh());

        $this->assertDatabaseCount('findings', 1);
        $finding = $finding->fresh();
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
    }

    public function test_expiring_soon_certificate_creates_medium_severity_finding(): void
    {
        Http::fake([
            'https://soon.example' => Http::response('ok', 200),
            'http://soon.example' => Http::response('', 301, ['Location' => 'https://soon.example/']),
        ]);

        $this->travelTo(new DateTimeImmutable('2026-08-07T12:00:00Z'));

        $host = 'soon.example';

        $this->mockSslProbe([
            'subject_common_name' => $host,
            'issuer_common_name' => 'Example CA',
            'valid_from' => '2025-08-07T12:00:00Z',
            'valid_to' => '2026-08-10T12:00:00Z',
            'observed_at' => '2026-08-07T12:00:00Z',
            'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
            'host' => $host,
            'present' => true,
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://soon.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->where('category', 'transport')->first();
        $this->assertNotNull($finding);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('expiring_within_7_days', (string) $finding->summary);
    }

    public function test_missing_certificate_creates_high_severity_finding(): void
    {
        Http::fake([
            'https://missing-cert.example' => Http::response('ok', 200),
            'http://missing-cert.example' => Http::response('', 301, ['Location' => 'https://missing-cert.example/']),
        ]);

        $this->travelTo(new DateTimeImmutable('2026-08-07T12:00:00Z'));

        $host = 'missing-cert.example';

        $this->mockSslProbe([
            'subject_common_name' => null,
            'issuer_common_name' => null,
            'valid_from' => null,
            'valid_to' => null,
            'observed_at' => '2026-08-07T12:00:00Z',
            'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
            'host' => $host,
            'present' => false,
            'error_class' => 'certificate_missing',
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing-cert.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->where('category', 'transport')->first();
        $this->assertNotNull($finding);
        $this->assertSame('HTTPS/TLS certificate problem', $finding->title);
        $this->assertSame('high', $finding->severity);
        $this->assertSame(hash('sha256', 'https-tls-validity|host=missing-cert.example'), $finding->fingerprint);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('certificate_missing', (string) $finding->summary);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mockSslProbe(array $payload): void
    {
        $probe = Mockery::mock(SslCertificateProbe::class);
        $probe->shouldReceive('probe')->andReturn($payload);
        $this->app->instance(SslCertificateProbe::class, $probe);
    }
}
