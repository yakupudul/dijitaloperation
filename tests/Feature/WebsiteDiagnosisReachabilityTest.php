<?php

namespace Tests\Feature;

use App\Jobs\DiagnoseWebsiteJob;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Services\WebsiteDiagnosisService;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WebsiteDiagnosisReachabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidSslCertificate();
    }

    public function test_diagnose_website_job_creates_run_and_normalized_http_fetch_evidence_on_success(): void
    {
        Http::fake([
            'https://acme.example/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
            'https://acme.example' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'module_id' => 'website',
            'domain' => 'acme.example',
            'primary_url' => 'https://acme.example',
        ]);

        $run = (new DiagnoseWebsiteJob($asset))->handle(app(WebsiteDiagnosisService::class));

        $this->assertInstanceOf(Run::class, $run);
        $this->assertSame('completed', $run->status);
        $this->assertSame(WebsiteDiagnosisService::MODULE_ID, $run->module_id);
        $this->assertSame($asset->id, $run->digital_asset_id);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $this->assertDatabaseCount('evidence', 2);

        $evidence = Evidence::query()->where('run_id', $run->id)->where('type', 'http_fetch')->first();
        $this->assertNotNull($evidence);
        $this->assertSame($asset->id, $evidence->digital_asset_id);
        $this->assertSame(WebsiteDiagnosisService::MODULE_ID, $evidence->source_module);
        $this->assertSame('http_fetch', $evidence->type);
        $this->assertSame('Primary URL HTTP fetch', $evidence->title);
        $this->assertNotNull($evidence->observed_at);

        $payload = $evidence->payload;
        $this->assertSame('https://acme.example', $payload['url']);
        $this->assertSame(200, $payload['status_code']);
        $this->assertSame('https://acme.example', $payload['effective_url']);
        $this->assertTrue($payload['is_https']);
        $this->assertTrue($payload['response_is_ok']);

        $this->assertSame(0, Finding::query()->where('digital_asset_id', $asset->id)->count());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://acme.example'
                && $request->method() === 'GET';
        });
    }

    public function test_reachability_finding_is_created_then_upserted_on_fingerprint_without_duplicates(): void
    {
        Http::fake([
            'https://down.example' => Http::response('upstream error', 503),
            'https://down.example/*' => Http::response('upstream error', 503),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'module_id' => 'website',
            'domain' => 'down.example',
            'primary_url' => 'https://down.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);

        $firstRun = (new DiagnoseWebsiteJob($asset))->handle($service);

        $this->assertSame('completed', $firstRun->status);
        $this->assertDatabaseCount('findings', 1);

        $finding = Finding::query()->where('digital_asset_id', $asset->id)->first();
        $this->assertNotNull($finding);
        $this->assertSame(WebsiteDiagnosisService::MODULE_ID, $finding->source_module);
        $this->assertSame('availability', $finding->category);
        $this->assertSame('critical', $finding->severity);
        $this->assertSame('Website not reachable', $finding->title);
        $this->assertStringContainsString('https://down.example', (string) $finding->summary);
        $this->assertStringContainsString('503', (string) $finding->summary);
        $this->assertSame('open', $finding->status);
        $this->assertSame($firstRun->id, $finding->last_run_id);
        $this->assertNotNull($finding->first_seen_at);
        $this->assertNotNull($finding->last_seen_at);
        $this->assertTrue($finding->first_seen_at->equalTo($finding->last_seen_at));

        $expectedFingerprint = hash('sha256', 'reachability-http|url=https://down.example');
        $this->assertSame($expectedFingerprint, $finding->fingerprint);

        $firstSeenAt = $finding->first_seen_at->copy();

        $this->travel(5)->minutes();

        $secondRun = (new DiagnoseWebsiteJob($asset->fresh()))->handle($service);

        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertDatabaseCount('findings', 1);
        $this->assertDatabaseCount('runs', 2);
        $this->assertDatabaseCount('evidence', 4);

        $finding = $finding->fresh();
        $this->assertNotNull($finding);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeenAt));
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertTrue($finding->last_seen_at->greaterThan($firstSeenAt));
        $this->assertSame('open', $finding->status);
    }

    public function test_connection_failure_persists_evidence_and_reachability_finding(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host');
        });

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $this->assertSame('completed', $run->status);

        $evidence = Evidence::query()->where('run_id', $run->id)->where('type', 'http_fetch')->first();
        $this->assertNotNull($evidence);
        $this->assertNull($evidence->payload['status_code']);
        $this->assertFalse($evidence->payload['response_is_ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);
        $this->assertTrue($evidence->payload['is_https']);

        $this->assertSame(1, Finding::query()->where('category', 'availability')->count());
        $finding = Finding::query()->where('category', 'availability')->first();
        $this->assertSame('Website not reachable', $finding->title);
        $this->assertStringContainsString('connection_error', (string) $finding->summary);
    }

    public function test_client_error_4xx_does_not_create_reachability_finding(): void
    {
        Http::fake([
            'https://gone.example' => Http::response('not found', 404),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://gone.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $evidence = Evidence::query()->where('run_id', $run->id)->where('type', 'http_fetch')->first();
        $this->assertSame(404, $evidence->payload['status_code']);
        $this->assertFalse($evidence->payload['response_is_ok']);
        $this->assertSame(0, Finding::query()->where('category', 'availability')->count());
    }

    private function stubValidSslCertificate(): void
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
