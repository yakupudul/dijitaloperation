<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Services\WebsiteDiagnosisService;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DiagnoseWebsiteRobotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_valid_robots_txt_creates_evidence_without_finding(): void
    {
        Http::fake([
            'https://ok.example' => Http::response('ok', 200),
            'http://ok.example' => Http::response('', 301, ['Location' => 'https://ok.example/']),
            'https://ok.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ok.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $robots = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_ROBOTS)
            ->first();

        $this->assertNotNull($robots);
        $this->assertSame('https://ok.example/robots.txt', $robots->payload['robots_url']);
        $this->assertTrue($robots->payload['present']);
        $this->assertTrue($robots->payload['parse_ok']);
        $this->assertNull($robots->payload['reason_code']);

        $httpFetch = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', 'http_fetch')
            ->where('title', 'robots.txt HTTP fetch')
            ->first();

        $this->assertNotNull($httpFetch);
        $this->assertSame(200, $httpFetch->payload['status_code']);

        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'robots.txt problem')
                ->count(),
        );
        $this->assertContains(
            WebsiteDiagnosisService::CATALOG_ROBOTS_TXT_AVAILABILITY,
            $run->metadata['checks'] ?? [],
        );
    }

    public function test_robots_5xx_upserts_indexability_finding_with_catalog_fingerprint(): void
    {
        Http::fake([
            'https://broken.example' => Http::response('ok', 200),
            'http://broken.example' => Http::response('', 301, ['Location' => 'https://broken.example/']),
            'https://broken.example/robots.txt' => Http::response('nope', 503),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://broken.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);
        $firstRun = $service->diagnose($asset);

        $expectedFingerprint = hash('sha256', 'robots-txt-availability|host=broken.example');

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'robots.txt problem')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('indexability', $finding->category);
        $this->assertSame('medium', $finding->severity);
        $this->assertEqualsWithDelta(0.9000, (float) $finding->confidence, 0.0001);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
        $this->assertSame($firstRun->id, $finding->last_run_id);
        $this->assertStringContainsString('https://broken.example/robots.txt', (string) $finding->summary);
        $this->assertStringContainsString('503', (string) $finding->summary);
        $this->assertStringContainsString('parse_ok=false', (string) $finding->summary);

        $robots = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_ROBOTS)
            ->first();
        $this->assertSame('fetch_5xx', $robots->payload['reason_code']);

        $this->travel(5)->minutes();

        $secondRun = $service->diagnose($asset->fresh());

        $this->assertDatabaseCount('findings', 1);
        $finding = $finding->fresh();
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
    }

    public function test_malformed_robots_body_creates_low_severity_finding(): void
    {
        Http::fake([
            'https://weird.example' => Http::response('ok', 200),
            'http://weird.example' => Http::response('', 301, ['Location' => 'https://weird.example/']),
            'https://weird.example/robots.txt' => Http::response("this is not a robots file\n", 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://weird.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'robots.txt problem')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('low', $finding->severity);
        $this->assertEqualsWithDelta(0.7000, (float) $finding->confidence, 0.0001);
        $this->assertSame($run->id, $finding->last_run_id);

        $robots = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_ROBOTS)
            ->first();
        $this->assertSame('malformed', $robots->payload['reason_code']);
        $this->assertFalse($robots->payload['parse_ok']);
    }

    public function test_missing_robots_404_does_not_create_finding(): void
    {
        Http::fake([
            'https://absent.example' => Http::response('ok', 200),
            'http://absent.example' => Http::response('', 301, ['Location' => 'https://absent.example/']),
            'https://absent.example/robots.txt' => Http::response('not found', 404),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://absent.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $robots = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_ROBOTS)
            ->first();

        $this->assertNotNull($robots);
        $this->assertSame(404, $robots->payload['status_code']);
        $this->assertFalse($robots->payload['present']);
        $this->assertNull($robots->payload['reason_code']);

        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'robots.txt problem')
                ->count(),
        );
    }

    public function test_robots_connection_failure_creates_medium_finding(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/robots.txt')) {
                throw new ConnectionException('Could not resolve robots host');
            }

            if (str_starts_with($request->url(), 'http://')) {
                return Http::response('', 301, ['Location' => 'https://flaky-robots.example/']);
            }

            return Http::response('ok', 200);
        });

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://flaky-robots.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'robots.txt problem')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('connection_error', (string) $finding->summary);

        $robots = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_ROBOTS)
            ->first();
        $this->assertSame('connection', $robots->payload['reason_code']);
        $this->assertSame('connection', $robots->payload['error_class']);
    }

    private function stubValidTlsCertificate(): void
    {
        $probe = Mockery::mock(SslCertificateProbe::class);
        $probe->shouldReceive('probe')->andReturnUsing(function (string $host): array {
            return [
                'subject_common_name' => $host,
                'issuer_common_name' => 'Stub CA',
                'valid_from' => now()->subYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'valid_to' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'fetch_method' => SslCertParser::FETCH_METHOD_PHP_STREAM,
                'host' => strtolower($host),
                'present' => true,
            ];
        });

        $this->app->instance(SslCertificateProbe::class, $probe);
    }
}
