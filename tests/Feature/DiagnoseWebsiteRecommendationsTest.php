<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Services\WebsiteDiagnosisService;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DiagnoseWebsiteRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubValidTlsCertificate();
    }

    public function test_finding_upsert_creates_linked_open_recommendation(): void
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

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'Sitemap missing or unreadable')
            ->first();

        $this->assertNotNull($finding);

        $recommendation = Recommendation::query()
            ->where('finding_id', $finding->id)
            ->where('source_module', WebsiteDiagnosisService::MODULE_ID)
            ->first();

        $this->assertNotNull($recommendation);
        $this->assertSame($asset->id, $recommendation->digital_asset_id);
        $this->assertSame('Fix: Sitemap missing or unreadable', $recommendation->title);
        $this->assertSame('medium', $recommendation->priority);
        $this->assertSame('open', $recommendation->status);
        $this->assertStringContainsString('Publish a UTF-8 XML sitemap', (string) $recommendation->action);
        $this->assertSame($finding->summary, $recommendation->rationale);
        $this->assertSame($firstRun->id, $finding->last_run_id);

        $this->travel(5)->minutes();

        $service->diagnose($asset->fresh());

        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $recommendation = $recommendation->fresh();
        $this->assertSame('open', $recommendation->status);
        $this->assertStringContainsString('Publish a UTF-8 XML sitemap', (string) $recommendation->action);
    }

    public function test_converted_recommendation_status_is_preserved_on_rerun(): void
    {
        Http::fake([
            'https://broken.example' => Http::response('ok', 200),
            'http://broken.example' => Http::response('', 301, ['Location' => 'https://broken.example/']),
            'https://broken.example/robots.txt' => Http::response('nope', 503),
            'https://broken.example/sitemap.xml' => Http::response($this->validEmptySitemap(), 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://broken.example',
        ]);

        $service = app(WebsiteDiagnosisService::class);
        $service->diagnose($asset);

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('title', 'robots.txt problem')
            ->first();

        $recommendation = Recommendation::query()->where('finding_id', $finding->id)->first();
        $this->assertNotNull($recommendation);

        $recommendation->update(['status' => 'converted']);

        $service->diagnose($asset->fresh());

        $recommendation = $recommendation->fresh();
        $this->assertSame('converted', $recommendation->status);
        $this->assertStringContainsString('Restore /robots.txt', (string) $recommendation->action);
        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
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
