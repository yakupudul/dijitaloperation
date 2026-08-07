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

class DiagnoseWebsiteRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_http_to_https_upgrade_creates_redirect_evidence_without_finding(): void
    {
        Http::fake([
            'https://secure.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://secure.example' => Http::response('ok', 200),
            'http://secure.example' => Http::response('', 301, ['Location' => 'https://secure.example/']),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://secure.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $redirects = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_REDIRECTS)
            ->first();

        $this->assertNotNull($redirects);
        $this->assertSame('http://secure.example', $redirects->payload['start_url']);
        $this->assertSame('https://secure.example/', $redirects->payload['final_url']);
        $this->assertSame(1, $redirects->payload['hop_count']);
        $this->assertTrue($redirects->payload['upgraded_to_https_same_host']);

        $httpEntrypoint = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', 'http_fetch')
            ->where('title', 'HTTP entrypoint fetch')
            ->first();

        $this->assertNotNull($httpEntrypoint);
        $this->assertSame('http://secure.example', $httpEntrypoint->payload['url']);

        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'HTTP does not upgrade to HTTPS')
                ->count(),
        );
        $this->assertContains(
            WebsiteDiagnosisService::CATALOG_REDIRECT_HTTP_TO_HTTPS,
            $run->metadata['checks'] ?? [],
        );
    }

    public function test_missing_https_upgrade_upserts_transport_finding_with_catalog_fingerprint(): void
    {
        Http::fake([
            'https://plain.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://plain.example' => Http::response('ok', 200),
            'http://plain.example' => Http::response('still http', 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://plain.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);
        $firstRun = $service->diagnose($asset);

        $expectedFingerprint = hash('sha256', 'redirect-http-to-https|host=plain.example');

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'HTTP does not upgrade to HTTPS')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('transport', $finding->category);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
        $this->assertSame($firstRun->id, $finding->last_run_id);
        $this->assertStringContainsString('http://plain.example', (string) $finding->summary);
        $this->assertStringContainsString('0 redirect hop(s)', (string) $finding->summary);

        $this->travel(5)->minutes();

        $secondRun = $service->diagnose($asset->fresh());

        $this->assertDatabaseCount('findings', 1);
        $finding = $finding->fresh();
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
    }

    public function test_http_to_http_redirect_without_https_creates_finding(): void
    {
        Http::fake([
            'https://loop.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://loop.example' => Http::response('ok', 200),
            'http://loop.example' => Http::response('', 302, ['Location' => 'http://loop.example/home']),
            'http://loop.example/home' => Http::response('home', 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://loop.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'HTTP does not upgrade to HTTPS')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('1 redirect hop(s)', (string) $finding->summary);
        $this->assertStringContainsString('http://loop.example/home', (string) $finding->summary);

        $redirects = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_REDIRECTS)
            ->first();

        $this->assertFalse($redirects->payload['upgraded_to_https_same_host']);
        $this->assertSame(1, $redirects->payload['hop_count']);
    }

    public function test_http_entrypoint_connection_failure_does_not_create_redirect_finding(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nDisallow:\n", 200);
            }

            if (str_starts_with($request->url(), 'http://')) {
                throw new ConnectionException('Could not connect to HTTP entrypoint');
            }

            return Http::response('ok', 200);
        });

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://flaky.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $redirects = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_REDIRECTS)
            ->first();

        $this->assertNotNull($redirects);
        $this->assertSame('connection', $redirects->payload['error_class']);
        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'HTTP does not upgrade to HTTPS')
                ->count(),
        );
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
