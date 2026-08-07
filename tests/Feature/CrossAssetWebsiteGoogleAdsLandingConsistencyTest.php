<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\GoogleAdsLandingFinalUrlsCollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossAssetWebsiteGoogleAdsLandingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mismatch_upserts_finding_on_website_with_pack_fingerprint(): void
    {
        [$website, $ads] = $this->pairedAssets();

        $this->seedWebsiteHttpFetch($website, 'https://www.acme.example/');
        $this->seedAdsLandingUrls($ads, [
            'https://other-landing.example/campaign',
            'https://www.acme.example/ok',
        ]);

        $service = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class);
        $firstRun = $service->analyze($website);
        $secondRun = $service->analyze($website);

        $this->assertSame('completed', $firstRun->status);
        $this->assertSame(CrossAssetWebsiteGoogleAdsLandingConsistencyService::MODULE_ID, $firstRun->module_id);
        $this->assertTrue($firstRun->metadata['compared'] ?? false);
        $this->assertFalse($firstRun->metadata['hosts_match'] ?? true);
        $this->assertArrayHasKey('skip_reason', $firstRun->metadata);
        $this->assertNull($firstRun->metadata['skip_reason']);

        $comparison = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', CrossAssetWebsiteGoogleAdsLandingConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertSame('www.acme.example', $comparison->payload['website_host']);
        $this->assertContains('other-landing.example', $comparison->payload['ads_final_url_hosts']);
        $this->assertFalse($comparison->payload['hosts_match']);
        $this->assertSame($ads->id, $comparison->payload['related_digital_asset_id']);

        $expectedFingerprint = hash('sha256', implode('|', [
            CrossAssetWebsiteGoogleAdsLandingConsistencyService::PACK_ID,
            'primary_asset_id='.$website->id,
            'related_asset_id='.$ads->id,
        ]));

        $findings = Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', $expectedFingerprint)
            ->get();

        $this->assertCount(1, $findings);
        $finding = $findings->first();
        $this->assertSame('cross-channel', $finding->category);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame('open', $finding->status);
        $this->assertSame(CrossAssetWebsiteGoogleAdsLandingConsistencyService::MODULE_ID, $finding->source_module);
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertStringContainsString((string) $ads->id, (string) $finding->summary);

        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $ads->id)->count());
    }

    public function test_matching_hosts_create_comparison_evidence_without_finding(): void
    {
        [$website, $ads] = $this->pairedAssets();

        $this->seedWebsiteHttpFetch($website, 'https://acme.example/about');
        $this->seedAdsLandingUrls($ads, [
            'https://acme.example/a',
            'https://acme.example/b',
        ]);

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($website);

        $this->assertTrue($run->metadata['compared'] ?? false);
        $this->assertTrue($run->metadata['hosts_match'] ?? false);
        $this->assertSame(0, Finding::query()->count());

        $comparison = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', CrossAssetWebsiteGoogleAdsLandingConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertTrue($comparison->payload['hosts_match']);
    }

    public function test_missing_google_ads_asset_skips_without_finding(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://solo.example',
        ]);
        $this->seedWebsiteHttpFetch($website, 'https://solo.example');

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($website);

        $this->assertSame('missing_google_ads_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertFalse($run->metadata['compared'] ?? true);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_ambiguous_google_ads_assets_skip_without_inventing_pair(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://acme.example',
        ]);
        DigitalAsset::factory()->count(2)->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
        ]);
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($website);

        $this->assertSame('ambiguous_google_ads_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_ads_landing_evidence_skips_honestly(): void
    {
        [$website, $ads] = $this->pairedAssets();
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($website);

        $this->assertSame('missing_google_ads_landing_final_urls_evidence', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
        $this->assertNotNull($ads->id);
    }

    public function test_empty_ads_final_url_hosts_skips_without_finding(): void
    {
        [$website, $ads] = $this->pairedAssets();
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');
        $this->seedAdsLandingUrls($ads, []);

        $run = app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($website);

        $this->assertSame('missing_google_ads_final_url_hosts', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rejects_non_website_asset(): void
    {
        $ads = DigitalAsset::factory()->create(['type' => 'google_ads']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('website Digital Asset');

        app(CrossAssetWebsiteGoogleAdsLandingConsistencyService::class)->analyze($ads);
    }

    /**
     * @return array{0: DigitalAsset, 1: DigitalAsset}
     */
    private function pairedAssets(): array
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://www.acme.example',
        ]);
        $ads = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
        ]);

        return [$website, $ads];
    }

    private function seedWebsiteHttpFetch(DigitalAsset $website, string $url): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $website->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $website->id,
            'type' => 'http_fetch',
            'payload' => [
                'url' => $url,
                'status_code' => 200,
                'effective_url' => $url,
                'is_https' => true,
                'response_is_ok' => true,
                'error_class' => null,
                'error_or_status' => '200',
            ],
        ]);
    }

    /**
     * @param  list<string>  $finalUrls
     */
    private function seedAdsLandingUrls(DigitalAsset $ads, array $finalUrls): void
    {
        $hosts = [];
        foreach ($finalUrls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        $run = Run::factory()->create([
            'digital_asset_id' => $ads->id,
            'module_id' => GoogleAdsLandingFinalUrlsCollectService::MODULE_ID,
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $ads->id,
            'source_module' => GoogleAdsLandingFinalUrlsCollectService::MODULE_ID,
            'type' => GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS,
            'payload' => [
                'requested_customer_id' => '1111111111',
                'final_urls' => $finalUrls,
                'final_url_hosts' => array_values(array_unique($hosts)),
                'final_url_count' => count($finalUrls),
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'google_ads_search_gaql',
            ],
        ]);
    }
}
