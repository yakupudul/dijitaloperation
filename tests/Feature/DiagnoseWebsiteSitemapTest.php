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

class DiagnoseWebsiteSitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_valid_default_sitemap_creates_evidence_without_finding(): void
    {
        Http::fake([
            'https://ok.example' => Http::response('ok', 200),
            'http://ok.example' => Http::response('', 301, ['Location' => 'https://ok.example/']),
            'https://ok.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://ok.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200, [
                'Content-Type' => 'application/xml',
            ]),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ok.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $sitemap = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_SITEMAP)
            ->first();

        $this->assertNotNull($sitemap);
        $this->assertSame('https://ok.example/sitemap.xml', $sitemap->payload['sitemap_url']);
        $this->assertSame(['https://ok.example/sitemap.xml'], $sitemap->payload['tried_urls']);
        $this->assertFalse($sitemap->payload['candidates_from_robots']);
        $this->assertTrue($sitemap->payload['present']);
        $this->assertTrue($sitemap->payload['parse_ok']);
        $this->assertSame('urlset', $sitemap->payload['root_element']);
        $this->assertSame(0, $sitemap->payload['url_count']);
        $this->assertSame('ok', $sitemap->payload['last_outcome']);
        $this->assertNull($sitemap->payload['reason_code']);

        $httpFetch = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', 'http_fetch')
            ->where('title', 'sitemap HTTP fetch')
            ->first();

        $this->assertNotNull($httpFetch);
        $this->assertSame(200, $httpFetch->payload['status_code']);

        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'Sitemap missing or unreadable')
                ->count(),
        );
        $this->assertContains(
            WebsiteDiagnosisService::CATALOG_SITEMAP_XML_AVAILABILITY,
            $run->metadata['checks'] ?? [],
        );
    }

    public function test_missing_default_sitemap_upserts_finding_with_medium_confidence(): void
    {
        Http::fake([
            'https://absent.example' => Http::response('ok', 200),
            'http://absent.example' => Http::response('', 301, ['Location' => 'https://absent.example/']),
            'https://absent.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://absent.example/sitemap.xml' => Http::response('not found', 404),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://absent.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);
        $firstRun = $service->diagnose($asset);

        $expectedFingerprint = hash('sha256', 'sitemap-xml-availability|host=absent.example');

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Sitemap missing or unreadable')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('indexability', $finding->category);
        $this->assertSame('medium', $finding->severity);
        $this->assertEqualsWithDelta(0.7000, (float) $finding->confidence, 0.0001);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
        $this->assertSame($firstRun->id, $finding->last_run_id);
        $this->assertStringContainsString('https://absent.example/sitemap.xml', (string) $finding->summary);
        $this->assertStringContainsString('last_outcome=status_404', (string) $finding->summary);

        $sitemap = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_SITEMAP)
            ->first();
        $this->assertSame('not_found', $sitemap->payload['reason_code']);
        $this->assertSame('status_404', $sitemap->payload['last_outcome']);

        $this->travel(5)->minutes();

        $secondRun = $service->diagnose($asset->fresh());

        $this->assertDatabaseCount('findings', 1);
        $finding = $finding->fresh();
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
    }

    public function test_malformed_sitemap_body_creates_medium_finding(): void
    {
        Http::fake([
            'https://weird.example' => Http::response('ok', 200),
            'http://weird.example' => Http::response('', 301, ['Location' => 'https://weird.example/']),
            'https://weird.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://weird.example/sitemap.xml' => Http::response('<html>nope</html>', 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://weird.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Sitemap missing or unreadable')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('medium', $finding->severity);
        $this->assertEqualsWithDelta(0.7000, (float) $finding->confidence, 0.0001);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('last_outcome=malformed_xml', (string) $finding->summary);

        $sitemap = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_SITEMAP)
            ->first();
        $this->assertSame('malformed', $sitemap->payload['reason_code']);
        $this->assertFalse($sitemap->payload['parse_ok']);
    }

    public function test_robots_declared_sitemap_failure_uses_high_confidence(): void
    {
        Http::fake([
            'https://declared.example' => Http::response('ok', 200),
            'http://declared.example' => Http::response('', 301, ['Location' => 'https://declared.example/']),
            'https://declared.example/robots.txt' => Http::response(
                "User-agent: *\nDisallow:\nSitemap: https://declared.example/custom-sitemap.xml\n",
                200,
            ),
            'https://declared.example/custom-sitemap.xml' => Http::response('gone', 410),
            'https://declared.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://declared.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Sitemap missing or unreadable')
            ->first();

        $this->assertNotNull($finding);
        $this->assertEqualsWithDelta(0.9000, (float) $finding->confidence, 0.0001);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('https://declared.example/custom-sitemap.xml', (string) $finding->summary);
        $this->assertStringNotContainsString('https://declared.example/sitemap.xml', (string) $finding->summary);

        $sitemap = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_SITEMAP)
            ->first();
        $this->assertTrue($sitemap->payload['candidates_from_robots']);
        $this->assertSame(['https://declared.example/custom-sitemap.xml'], $sitemap->payload['tried_urls']);
        $this->assertSame('status_410', $sitemap->payload['last_outcome']);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://declared.example/sitemap.xml');
    }

    public function test_robots_declared_second_candidate_can_pass(): void
    {
        Http::fake([
            'https://multi.example' => Http::response('ok', 200),
            'http://multi.example' => Http::response('', 301, ['Location' => 'https://multi.example/']),
            'https://multi.example/robots.txt' => Http::response(
                "User-agent: *\nDisallow:\nSitemap: https://multi.example/a.xml\nSitemap: https://multi.example/b.xml\n",
                200,
            ),
            'https://multi.example/a.xml' => Http::response('missing', 404),
            'https://multi.example/b.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://multi.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $sitemap = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_SITEMAP)
            ->first();

        $this->assertTrue($sitemap->payload['parse_ok']);
        $this->assertSame('https://multi.example/b.xml', $sitemap->payload['sitemap_url']);
        $this->assertSame(
            ['https://multi.example/a.xml', 'https://multi.example/b.xml'],
            $sitemap->payload['tried_urls'],
        );

        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'Sitemap missing or unreadable')
                ->count(),
        );
    }

    public function test_sitemap_connection_failure_creates_finding(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/sitemap.xml')) {
                throw new ConnectionException('Could not resolve sitemap host');
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nDisallow:\n", 200);
            }

            if (str_starts_with($request->url(), 'http://')) {
                return Http::response('', 301, ['Location' => 'https://flaky-sitemap.example/']);
            }

            return Http::response('ok', 200);
        });

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://flaky-sitemap.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Sitemap missing or unreadable')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('last_outcome=connection', (string) $finding->summary);

        $sitemap = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_SITEMAP)
            ->first();
        $this->assertSame('fetch_failure', $sitemap->payload['reason_code']);
        $this->assertSame('connection', $sitemap->payload['error_class']);
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
