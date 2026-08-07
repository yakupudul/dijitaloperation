<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Services\WebsiteDiagnosisService;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DiagnoseWebsiteCanonicalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_matching_absolute_canonical_creates_evidence_without_finding(): void
    {
        Http::fake([
            'https://ok.example' => Http::response($this->htmlWithCanonical('https://ok.example/'), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
            'http://ok.example' => Http::response('', 301, ['Location' => 'https://ok.example/']),
            'https://ok.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://ok.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ok.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $pageHtml = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_PAGE_HTML)
            ->first();

        $this->assertNotNull($pageHtml);
        $this->assertSame('absolute_single', $pageHtml->payload['canonical_state']);
        $this->assertSame(['https://ok.example/'], $pageHtml->payload['canonical_hrefs']);
        $this->assertTrue($pageHtml->payload['head_complete']);
        $this->assertSame([], $pageHtml->payload['telephone_candidates'] ?? null);

        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'Canonical link issue')
                ->count(),
        );
        $this->assertContains(
            WebsiteDiagnosisService::CATALOG_CANONICAL_LINK_CONSISTENCY,
            $run->metadata['checks'] ?? [],
        );
    }

    public function test_missing_canonical_upserts_medium_finding(): void
    {
        Http::fake([
            'https://missing.example' => Http::response($this->htmlWithoutCanonical(), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
            'http://missing.example' => Http::response('', 301, ['Location' => 'https://missing.example/']),
            'https://missing.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://missing.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://missing.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);
        $firstRun = $service->diagnose($asset);

        $expectedFingerprint = hash('sha256', 'canonical-link-consistency|url=https://missing.example');

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Canonical link issue')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('on-page', $finding->category);
        $this->assertSame('medium', $finding->severity);
        $this->assertEqualsWithDelta(0.9000, (float) $finding->confidence, 0.0001);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
        $this->assertSame($firstRun->id, $finding->last_run_id);
        $this->assertStringContainsString('canonical signal: missing', (string) $finding->summary);

        $this->travel(5)->minutes();

        $secondRun = $service->diagnose($asset->fresh());

        $this->assertDatabaseCount('findings', 1);
        $finding = $finding->fresh();
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertSame($expectedFingerprint, $finding->fingerprint);
    }

    public function test_relative_canonical_creates_low_severity_finding(): void
    {
        Http::fake([
            'https://relative.example' => Http::response($this->htmlWithCanonical('/'), 200),
            'http://relative.example' => Http::response('', 301, ['Location' => 'https://relative.example/']),
            'https://relative.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://relative.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://relative.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Canonical link issue')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('low', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('relative_only', (string) $finding->summary);
    }

    public function test_multiple_absolute_canonicals_create_conflict_finding(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html><head>
<link rel="canonical" href="https://multi.example/a">
<link rel="canonical" href="https://multi.example/b">
</head><body>ok</body></html>
HTML;

        Http::fake([
            'https://multi.example' => Http::response($html, 200),
            'http://multi.example' => Http::response('', 301, ['Location' => 'https://multi.example/']),
            'https://multi.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://multi.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://multi.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Canonical link issue')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('conflict_multiple', (string) $finding->summary);
    }

    public function test_canonical_mismatch_against_stable_redirect_landing_creates_finding(): void
    {
        Http::fake([
            'https://mismatch.example' => Http::response(
                $this->htmlWithCanonical('https://other.example/page'),
                200,
            ),
            'http://mismatch.example' => Http::response('', 301, ['Location' => 'https://mismatch.example/']),
            'https://mismatch.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://mismatch.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://mismatch.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Canonical link issue')
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertStringContainsString('conflict_mismatch', (string) $finding->summary);
        $this->assertStringContainsString('https://other.example/page', (string) $finding->summary);
    }

    public function test_page_html_includes_telephone_candidates_from_primary_html(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Example</title>
  <link rel="canonical" href="https://phone.example/">
</head>
<body>
  <a href="tel:+1-555-0199">Call us</a>
  <span itemprop="telephone">+1 (555) 0199</span>
</body>
</html>
HTML;

        Http::fake([
            'https://phone.example' => Http::response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
            'http://phone.example' => Http::response('', 301, ['Location' => 'https://phone.example/']),
            'https://phone.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://phone.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://phone.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $pageHtml = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_PAGE_HTML)
            ->first();

        $this->assertNotNull($pageHtml);
        $this->assertSame(['+1-555-0199', '+1 (555) 0199'], $pageHtml->payload['telephone_candidates']);
    }

    public function test_non_html_primary_body_skips_page_html_evidence(): void
    {
        Http::fake([
            'https://plain.example' => Http::response('ok', 200),
            'http://plain.example' => Http::response('', 301, ['Location' => 'https://plain.example/']),
            'https://plain.example/robots.txt' => Http::response("User-agent: *\nDisallow:\n", 200),
            'https://plain.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://plain.example',
        ]);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $this->assertSame(
            0,
            Evidence::query()
                ->where('run_id', $run->id)
                ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_PAGE_HTML)
                ->count(),
        );
        $this->assertSame(
            0,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('title', 'Canonical link issue')
                ->count(),
        );
    }

    private function htmlWithCanonical(string $href): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Example</title>
  <link rel="canonical" href="{$href}">
</head>
<body>ok</body>
</html>
HTML;
    }

    private function htmlWithoutCanonical(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Example</title>
</head>
<body>ok</body>
</html>
HTML;
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
