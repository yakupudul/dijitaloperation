<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\CrossAssetWebsiteGbpWebsiteUrlConsistencyService;
use App\Services\GoogleBusinessProfileConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossAssetWebsiteGbpWebsiteUrlConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mismatch_upserts_finding_on_website_with_pack_fingerprint(): void
    {
        [$website, $gbp] = $this->pairedAssets();

        $this->seedWebsiteHttpFetch($website, 'https://www.acme.example/');
        $this->seedGbpLocationAccess($gbp, 'https://maps-listed.example/');

        $service = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class);
        $firstRun = $service->analyze($website);
        $secondRun = $service->analyze($website);

        $this->assertSame('completed', $firstRun->status);
        $this->assertSame(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::MODULE_ID, $firstRun->module_id);
        $this->assertTrue($firstRun->metadata['compared'] ?? false);
        $this->assertFalse($firstRun->metadata['hosts_match'] ?? true);
        $this->assertNull($firstRun->metadata['skip_reason'] ?? 'x');

        $comparison = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', CrossAssetWebsiteGbpWebsiteUrlConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertSame('www.acme.example', $comparison->payload['website_host']);
        $this->assertSame('maps-listed.example', $comparison->payload['gbp_host']);
        $this->assertFalse($comparison->payload['hosts_match']);
        $this->assertSame($gbp->id, $comparison->payload['related_digital_asset_id']);

        $expectedFingerprint = hash('sha256', implode('|', [
            CrossAssetWebsiteGbpWebsiteUrlConsistencyService::PACK_ID,
            'primary_asset_id='.$website->id,
            'related_asset_id='.$gbp->id,
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
        $this->assertSame(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::MODULE_ID, $finding->source_module);
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertStringContainsString((string) $gbp->id, (string) $finding->summary);

        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $gbp->id)->count());
    }

    public function test_matching_hosts_create_comparison_evidence_without_finding(): void
    {
        [$website, $gbp] = $this->pairedAssets();

        $this->seedWebsiteHttpFetch($website, 'https://acme.example/about');
        $this->seedGbpLocationAccess($gbp, 'https://acme.example');

        $run = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertTrue($run->metadata['compared'] ?? false);
        $this->assertTrue($run->metadata['hosts_match'] ?? false);
        $this->assertSame(0, Finding::query()->count());

        $comparison = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', CrossAssetWebsiteGbpWebsiteUrlConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertTrue($comparison->payload['hosts_match']);
    }

    public function test_missing_gbp_asset_skips_without_finding(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://solo.example',
        ]);
        $this->seedWebsiteHttpFetch($website, 'https://solo.example');

        $run = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_gbp_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertFalse($run->metadata['compared'] ?? true);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_ambiguous_gbp_assets_skip_without_inventing_pair(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://acme.example',
        ]);
        DigitalAsset::factory()->count(2)->create([
            'brand_id' => $brand->id,
            'type' => 'google_business_profile',
        ]);
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');

        $run = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('ambiguous_gbp_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_gbp_website_uri_skips_honestly(): void
    {
        [$website, $gbp] = $this->pairedAssets();
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');
        $this->seedGbpLocationAccess($gbp, null);

        $run = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_gbp_website_uri', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_website_http_fetch_evidence_skips(): void
    {
        [$website, $gbp] = $this->pairedAssets();
        $this->seedGbpLocationAccess($gbp, 'https://acme.example');

        $run = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_website_http_fetch_evidence', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rejects_non_website_primary_asset(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'google_business_profile',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($asset);
    }

    public function test_does_not_pair_across_brands(): void
    {
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();

        $website = DigitalAsset::factory()->create([
            'brand_id' => $brandA->id,
            'type' => 'website',
            'primary_url' => 'https://acme.example',
        ]);
        $gbpOtherBrand = DigitalAsset::factory()->create([
            'brand_id' => $brandB->id,
            'type' => 'google_business_profile',
        ]);

        $this->seedWebsiteHttpFetch($website, 'https://acme.example');
        $this->seedGbpLocationAccess($gbpOtherBrand, 'https://other.example');

        $run = app(CrossAssetWebsiteGbpWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_gbp_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
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
            'primary_url' => 'https://acme.example',
        ]);

        $gbp = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_business_profile',
        ]);

        return [$website, $gbp];
    }

    private function seedWebsiteHttpFetch(DigitalAsset $website, string $url): Evidence
    {
        $priorRun = Run::factory()->create([
            'digital_asset_id' => $website->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);

        return Evidence::factory()->create([
            'run_id' => $priorRun->id,
            'digital_asset_id' => $website->id,
            'source_module' => 'website-diagnosis',
            'type' => 'http_fetch',
            'title' => 'Primary URL HTTP fetch',
            'payload' => [
                'url' => $url,
                'status_code' => 200,
                'effective_url' => $url,
                'is_https' => str_starts_with($url, 'https://'),
                'response_is_ok' => true,
                'error_class' => null,
                'error_or_status' => '200',
            ],
            'observed_at' => now(),
        ]);
    }

    private function seedGbpLocationAccess(DigitalAsset $gbp, ?string $websiteUri): Evidence
    {
        $priorRun = Run::factory()->create([
            'digital_asset_id' => $gbp->id,
            'module_id' => GoogleBusinessProfileConnectionProbeService::MODULE_ID,
            'status' => 'completed',
        ]);

        return Evidence::factory()->create([
            'run_id' => $priorRun->id,
            'digital_asset_id' => $gbp->id,
            'source_module' => GoogleBusinessProfileConnectionProbeService::MODULE_ID,
            'type' => GoogleBusinessProfileConnectionProbeService::EVIDENCE_TYPE_GBP_LOCATION_ACCESS,
            'title' => 'Google Business Profile location access',
            'payload' => [
                'requested_location_name' => 'locations/123',
                'location_name' => 'locations/123',
                'title' => 'Acme Local',
                'website_uri' => $websiteUri,
                'primary_phone' => '+1 555-0100',
                'primary_category' => 'Coffee shop',
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
            ],
            'observed_at' => now(),
        ]);
    }
}
