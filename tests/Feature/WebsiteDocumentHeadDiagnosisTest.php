<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Services\WebsiteDiagnosisService;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use MoxDop\Website\Diagnosis\DocumentHeadCatalog;
use Tests\TestCase;

class WebsiteDocumentHeadDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    private string $currentHtml = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_title_missing_creates_finding_and_recommendation(): void
    {
        $asset = $this->website('https://head-missing.example');
        $this->fakeSite($asset->primary_url, <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A solid description for the page that sits in the heuristic band.">
  <meta property="og:title" content="Demo">
  <meta property="og:description" content="Demo description">
  <meta property="og:image" content="https://head-missing.example/og.png">
</head>
<body>ok</body>
</html>
HTML);

        $run = app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame('open', $finding->status);
        $this->assertSame('high', $finding->severity);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(
            DocumentHeadCatalog::recommendationAction(DocumentHeadCatalog::RULE_TITLE_MISSING),
            Recommendation::query()->where('finding_id', $finding->id)->value('action')
        );
    }

    public function test_title_present_healthy_run_resolves_previous_title_finding(): void
    {
        $asset = $this->website('https://head-resolve.example');
        $this->fakeSite($asset->primary_url, <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A solid description for the page that sits in the heuristic band.">
  <meta property="og:title" content="Demo">
  <meta property="og:description" content="Demo description">
  <meta property="og:image" content="https://head-resolve.example/og.png">
</head>
<body>ok</body>
</html>
HTML);
        app(WebsiteDiagnosisService::class)->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->firstOrFail();
        $this->assertSame('open', $finding->status);

        $this->fakeSite($asset->primary_url, <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <title>Hello World Page</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A solid description for the page that sits in the heuristic band.">
  <meta property="og:title" content="Demo">
  <meta property="og:description" content="Demo description">
  <meta property="og:image" content="https://head-resolve.example/og.png">
</head>
<body>ok</body>
</html>
HTML);
        $run = app(WebsiteDiagnosisService::class)->diagnose($asset->fresh());

        $finding->refresh();
        $this->assertSame('resolved', $finding->status);
        $this->assertSame($run->id, $finding->last_run_id);
        $this->assertNotNull($finding->resolved_at);
        $this->assertSame(
            1,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
                ->count()
        );
    }

    public function test_failed_run_does_not_resolve_open_document_head_finding(): void
    {
        $asset = $this->website('https://head-fail.example');
        $this->fakeSite($asset->primary_url, <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A solid description for the page that sits in the heuristic band.">
  <meta property="og:title" content="Demo">
  <meta property="og:description" content="Demo description">
  <meta property="og:image" content="https://head-fail.example/og.png">
</head>
<body>ok</body>
</html>
HTML);
        app(WebsiteDiagnosisService::class)->diagnose($asset);

        Http::fake([
            '*' => Http::response('upstream unavailable', 503),
        ]);

        app(WebsiteDiagnosisService::class)->diagnose($asset->fresh());

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->firstOrFail();
        $this->assertSame('open', $finding->status);
        $this->assertNull($finding->resolved_at);
    }

    public function test_robots_noindex_and_malformed_json_ld_and_og_advisory(): void
    {
        $asset = $this->website('https://head-mixed.example');
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <title>Demo</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A solid description for the page that sits in the heuristic band.">
  <meta name="robots" content="noindex, nofollow">
  <meta property="og:title" content="Demo">
  <script type="application/ld+json">{not-json</script>
</head>
<body>ok</body>
</html>
HTML;
        $this->fakeSite($asset->primary_url, $html);

        app(WebsiteDiagnosisService::class)->diagnose($asset);

        $noindex = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_ROBOTS_NOINDEX)
            ->first();
        $malformed = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_JSONLD_MALFORMED)
            ->first();
        $og = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_OG_INCOMPLETE)
            ->first();

        $this->assertNotNull($noindex);
        $this->assertSame('medium', $noindex->severity);
        $this->assertStringContainsString('declares noindex', strtolower((string) $noindex->title));

        $this->assertNotNull($malformed);
        $this->assertSame('medium', $malformed->severity);

        $this->assertNotNull($og);
        $this->assertSame('low', $og->severity);

        $payload = Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', WebsiteDiagnosisService::EVIDENCE_TYPE_PAGE_HTML)
            ->latest('id')
            ->value('payload');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('document', $payload);
        $this->assertArrayHasKey('structured_data', $payload);
        $this->assertSame(1, (int) data_get($payload, 'structured_data.malformed_count'));
    }

    public function test_stable_fingerprint_and_no_duplicate_recommendations_across_runs(): void
    {
        $asset = $this->website('https://head-stable.example');
        $this->fakeSite($asset->primary_url, <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="A solid description for the page that sits in the heuristic band.">
  <meta property="og:title" content="Demo">
  <meta property="og:description" content="Demo description">
  <meta property="og:image" content="https://head-stable.example/og.png">
</head>
<body>ok</body>
</html>
HTML);

        app(WebsiteDiagnosisService::class)->diagnose($asset);
        app(WebsiteDiagnosisService::class)->diagnose($asset->fresh());

        $this->assertSame(
            1,
            Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
                ->count()
        );
        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->firstOrFail();
        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame('open', $finding->status);
        $this->assertNotNull($finding->last_run_id);
    }

    private function website(string $url): DigitalAsset
    {
        return DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => $url,
        ]);
    }

    private function fakeSite(string $primaryUrl, string $html): void
    {
        $this->currentHtml = $html;
        $host = parse_url($primaryUrl, PHP_URL_HOST);
        $https = 'https://'.$host;
        $http = 'http://'.$host;

        // Mutable callback so mid-test HTML swaps actually take effect.
        Http::fake(function ($request) use ($https, $http) {
            $url = $request->url();

            if (str_starts_with($url, $http)) {
                return Http::response('', 301, ['Location' => $https.'/']);
            }

            if (str_ends_with(parse_url($url, PHP_URL_PATH) ?? '/', '/robots.txt')) {
                return Http::response("User-agent: *\nDisallow:\n", 200);
            }

            if (str_ends_with(parse_url($url, PHP_URL_PATH) ?? '/', '/sitemap.xml')) {
                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
</urlset>
XML, 200);
            }

            return Http::response($this->currentHtml, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        });
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
