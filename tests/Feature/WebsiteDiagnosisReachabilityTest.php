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

class WebsiteDiagnosisReachabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_connection_failure_persists_evidence_and_reachability_finding(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nDisallow:\n", 200);
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response($this->validEmptySitemap(), 200);
            }

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
            'https://gone.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://gone.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
            'https://gone.example' => Http::response('not found', 404),
            'http://gone.example' => Http::response('', 301, ['Location' => 'https://gone.example/']),
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

    private function validEmptySitemap(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
</urlset>
XML;
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
