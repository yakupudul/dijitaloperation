<?php

namespace Tests\Feature;

use App\Jobs\DiagnoseWebsiteJob;
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

    public function test_expired_certificate_creates_ssl_evidence_and_high_severity_finding(): void
    {
        Http::fake([
            'https://expired.example' => Http::response('ok', 200),
            'https://expired.example/*' => Http::response('ok', 200),
        ]);

        $observedAt = new DateTimeImmutable('2026-08-07T12:00:00Z');
        $this->travelTo($observedAt);

        $validTo = '2026-01-01T00:00:00Z';
        $issuer = 'Test Intermediate CA';
        $host = 'expired.example';

        $this->mockSslProbe([
            'subject_common_name' => 'expired.example',
            'issuer_common_name' => $issuer,
            'valid_from' => '2024-01-01T00:00:00Z',
            'valid_to' => $validTo,
            'observed_at' => '2026-08-07T12:00:00Z',
            'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
            'host' => $host,
            'present' => true,
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'module_id' => 'website',
            'domain' => 'expired.example',
            'primary_url' => 'https://expired.example',
        ]);

        $run = (new DiagnoseWebsiteJob($asset))->handle(app(WebsiteDiagnosisService::class));

        $this->assertSame('completed', $run->status);

        $sslEvidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', 'ssl_certificate')
            ->first();

        $this->assertNotNull($sslEvidence);
        $this->assertSame(WebsiteDiagnosisService::MODULE_ID, $sslEvidence->source_module);
        $this->assertSame('SSL certificate', $sslEvidence->title);
        $this->assertSame($host, $sslEvidence->payload['host']);
        $this->assertSame($validTo, $sslEvidence->payload['valid_to']);
        $this->assertSame($issuer, $sslEvidence->payload['issuer_common_name']);
        $this->assertSame(SslCertParser::FETCH_METHOD_PHP_STREAM, $sslEvidence->payload['fetch_method']);
        $this->assertArrayNotHasKey('private_key', $sslEvidence->payload);
        $this->assertArrayNotHasKey('raw', $sslEvidence->payload);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('category', 'ssl')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('SSL certificate expired', $finding->title);
        $this->assertSame('high', $finding->severity);
        $this->assertSame('0.9000', (string) $finding->confidence);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertSame(sha1($host.'ssl'.$validTo.$issuer), $finding->fingerprint);
        $this->assertStringContainsString($validTo, (string) $finding->summary);
    }

    public function test_valid_certificate_creates_evidence_without_ssl_finding(): void
    {
        Http::fake([
            'https://healthy.example' => Http::response('ok', 200),
            'https://healthy.example/*' => Http::response('ok', 200),
        ]);

        $observedAt = new DateTimeImmutable('2026-08-07T12:00:00Z');
        $this->travelTo($observedAt);

        $this->mockSslProbe([
            'subject_common_name' => 'healthy.example',
            'issuer_common_name' => 'Public CA',
            'valid_from' => '2025-01-01T00:00:00Z',
            'valid_to' => '2027-01-01T00:00:00Z',
            'observed_at' => '2026-08-07T12:00:00Z',
            'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
            'host' => 'healthy.example',
            'present' => true,
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://healthy.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $this->assertSame(1, Evidence::query()->where('run_id', $run->id)->where('type', 'ssl_certificate')->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->where('category', 'ssl')->count());
    }

    public function test_certificate_expiring_within_seven_days_creates_medium_finding(): void
    {
        Http::fake([
            'https://soon.example' => Http::response('ok', 200),
            'https://soon.example/*' => Http::response('ok', 200),
        ]);

        $this->travelTo(new DateTimeImmutable('2026-08-07T12:00:00Z'));

        $validTo = '2026-08-10T12:00:00Z';
        $issuer = 'Soon CA';
        $host = 'soon.example';

        $this->mockSslProbe([
            'subject_common_name' => 'soon.example',
            'issuer_common_name' => $issuer,
            'valid_from' => '2025-08-10T12:00:00Z',
            'valid_to' => $validTo,
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

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->where('category', 'ssl')->first();
        $this->assertNotNull($finding);
        $this->assertSame('SSL certificate expiring soon', $finding->title);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertSame(sha1($host.'ssl'.$validTo.$issuer), $finding->fingerprint);
    }

    public function test_missing_certificate_creates_high_severity_finding(): void
    {
        Http::fake([
            'https://missing-cert.example' => Http::response('ok', 200),
            'https://missing-cert.example/*' => Http::response('ok', 200),
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

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->where('category', 'ssl')->first();
        $this->assertNotNull($finding);
        $this->assertSame('SSL certificate missing', $finding->title);
        $this->assertSame('high', $finding->severity);
        $this->assertSame(sha1($host.'ssl'), $finding->fingerprint);
        $this->assertSame($run->id, $finding->last_run_id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mockSslProbe(array $payload): void
    {
        $probe = Mockery::mock(SslCertificateProbe::class);
        $probe->shouldReceive('probe')->once()->andReturn($payload);
        $this->app->instance(SslCertificateProbe::class, $probe);
    }
}
