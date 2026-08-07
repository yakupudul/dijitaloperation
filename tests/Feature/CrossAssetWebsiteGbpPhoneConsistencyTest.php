<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\CrossAssetWebsiteGbpPhoneConsistencyService;
use App\Services\GoogleBusinessProfileConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossAssetWebsiteGbpPhoneConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mismatch_upserts_finding_on_website_with_pack_fingerprint(): void
    {
        [$website, $gbp] = $this->pairedAssets();

        $this->seedWebsitePageHtml($website, ['+1 555-0100']);
        $this->seedGbpLocationAccess($gbp, '+1 555-9999');

        $service = app(CrossAssetWebsiteGbpPhoneConsistencyService::class);
        $firstRun = $service->analyze($website);
        $secondRun = $service->analyze($website);

        $this->assertSame('completed', $firstRun->status);
        $this->assertSame(CrossAssetWebsiteGbpPhoneConsistencyService::MODULE_ID, $firstRun->module_id);
        $this->assertTrue($firstRun->metadata['compared'] ?? false);
        $this->assertFalse($firstRun->metadata['phones_match'] ?? true);
        $this->assertArrayHasKey('skip_reason', $firstRun->metadata);
        $this->assertNull($firstRun->metadata['skip_reason']);

        $comparison = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', CrossAssetWebsiteGbpPhoneConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertSame(['+1 555-0100'], $comparison->payload['website_telephone_candidates']);
        $this->assertSame('+1 555-9999', $comparison->payload['gbp_primary_phone']);
        $this->assertFalse($comparison->payload['phones_match']);
        $this->assertSame($gbp->id, $comparison->payload['related_digital_asset_id']);

        $expectedFingerprint = hash('sha256', implode('|', [
            CrossAssetWebsiteGbpPhoneConsistencyService::PACK_ID,
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
        $this->assertSame(CrossAssetWebsiteGbpPhoneConsistencyService::MODULE_ID, $finding->source_module);
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertStringContainsString((string) $gbp->id, (string) $finding->summary);

        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $gbp->id)->count());
    }

    public function test_matching_phones_create_comparison_evidence_without_finding(): void
    {
        [$website, $gbp] = $this->pairedAssets();

        $this->seedWebsitePageHtml($website, ['+1 (555) 010-1234', 'tel:+15550101234']);
        $this->seedGbpLocationAccess($gbp, '15550101234');

        $run = app(CrossAssetWebsiteGbpPhoneConsistencyService::class)->analyze($website);

        $this->assertTrue($run->metadata['compared'] ?? false);
        $this->assertTrue($run->metadata['phones_match'] ?? false);
        $this->assertSame(0, Finding::query()->count());

        $comparison = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', CrossAssetWebsiteGbpPhoneConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertTrue($comparison->payload['phones_match']);
    }

    public function test_missing_website_telephone_skips_honestly(): void
    {
        [$website, $gbp] = $this->pairedAssets();
        $this->seedWebsitePageHtml($website, []);
        $this->seedGbpLocationAccess($gbp, '+1 555-0100');

        $run = app(CrossAssetWebsiteGbpPhoneConsistencyService::class)->analyze($website);

        $this->assertSame('missing_website_telephone', $run->metadata['skip_reason'] ?? null);
        $this->assertFalse($run->metadata['compared'] ?? true);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_gbp_telephone_skips_honestly(): void
    {
        [$website, $gbp] = $this->pairedAssets();
        $this->seedWebsitePageHtml($website, ['+1 555-0100']);
        $this->seedGbpLocationAccess($gbp, null);

        $run = app(CrossAssetWebsiteGbpPhoneConsistencyService::class)->analyze($website);

        $this->assertSame('missing_gbp_telephone', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_gbp_asset_skips_without_finding(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://solo.example',
        ]);
        $this->seedWebsitePageHtml($website, ['+1 555-0100']);

        $run = app(CrossAssetWebsiteGbpPhoneConsistencyService::class)->analyze($website);

        $this->assertSame('missing_gbp_asset', $run->metadata['skip_reason'] ?? null);
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
        $this->seedWebsitePageHtml($website, ['+1 555-0100']);

        $run = app(CrossAssetWebsiteGbpPhoneConsistencyService::class)->analyze($website);

        $this->assertSame('ambiguous_gbp_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rejects_non_website_primary_asset(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'google_business_profile',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(CrossAssetWebsiteGbpPhoneConsistencyService::class)->analyze($asset);
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

    /**
     * @param  list<string>  $telephoneCandidates
     */
    private function seedWebsitePageHtml(DigitalAsset $website, array $telephoneCandidates): Evidence
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
            'type' => 'page_html',
            'title' => 'Primary page HTML',
            'payload' => [
                'final_url' => 'https://acme.example/',
                'status_code' => 200,
                'content_type' => 'text/html',
                'head_html' => '<head></head>',
                'head_truncated' => false,
                'head_complete' => true,
                'canonical_hrefs' => ['https://acme.example/'],
                'absolute_canonical_hrefs' => ['https://acme.example/'],
                'relative_canonical_hrefs' => [],
                'canonical_state' => 'absolute_single',
                'telephone_candidates' => $telephoneCandidates,
            ],
            'observed_at' => now(),
        ]);
    }

    private function seedGbpLocationAccess(DigitalAsset $gbp, ?string $primaryPhone): Evidence
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
                'website_uri' => 'https://acme.example',
                'primary_phone' => $primaryPhone,
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
