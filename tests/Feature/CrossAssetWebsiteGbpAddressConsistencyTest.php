<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\CrossAssetWebsiteGbpAddressConsistencyService;
use App\Services\GoogleBusinessProfileConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossAssetWebsiteGbpAddressConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mismatch_upserts_finding_on_website_with_pack_fingerprint(): void
    {
        [$website, $gbp] = $this->pairedAssets();

        $this->seedWebsitePageHtml($website, [[
            'street_address' => '123 Main St',
            'locality' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'formatted' => '123 Main St, Austin, TX, 78701, US',
        ]]);
        $this->seedGbpLocationAccess($gbp, [
            'region_code' => 'US',
            'postal_code' => '78701',
            'administrative_area' => 'TX',
            'locality' => 'Austin',
            'address_lines' => ['999 Other Rd'],
        ]);

        $service = app(CrossAssetWebsiteGbpAddressConsistencyService::class);
        $firstRun = $service->analyze($website);
        $secondRun = $service->analyze($website);

        $this->assertSame('completed', $firstRun->status);
        $this->assertTrue($firstRun->metadata['compared'] ?? false);
        $this->assertFalse($firstRun->metadata['addresses_match'] ?? true);
        $this->assertNull($firstRun->metadata['skip_reason']);

        $expectedFingerprint = hash('sha256', implode('|', [
            CrossAssetWebsiteGbpAddressConsistencyService::PACK_ID,
            'primary_asset_id='.$website->id,
            'related_asset_id='.$gbp->id,
        ]));

        $findings = Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', $expectedFingerprint)
            ->get();

        $this->assertCount(1, $findings);
        $this->assertSame($secondRun->id, $findings->first()->last_run_id);
        $this->assertSame(1, Recommendation::query()->where('finding_id', $findings->first()->id)->count());
    }

    public function test_matching_addresses_create_comparison_without_finding(): void
    {
        [$website, $gbp] = $this->pairedAssets();

        $this->seedWebsitePageHtml($website, [[
            'street_address' => '123 Main St',
            'locality' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'formatted' => '123 Main St, Austin, TX, 78701, US',
        ]]);
        $this->seedGbpLocationAccess($gbp, [
            'region_code' => 'US',
            'postal_code' => '78701',
            'administrative_area' => 'TX',
            'locality' => 'Austin',
            'address_lines' => ['123 Main St'],
        ]);

        $run = app(CrossAssetWebsiteGbpAddressConsistencyService::class)->analyze($website);

        $this->assertTrue($run->metadata['compared'] ?? false);
        $this->assertTrue($run->metadata['addresses_match'] ?? false);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_website_address_skips_honestly(): void
    {
        [$website, $gbp] = $this->pairedAssets();
        $this->seedWebsitePageHtml($website, []);
        $this->seedGbpLocationAccess($gbp, [
            'region_code' => 'US',
            'postal_code' => '78701',
            'administrative_area' => 'TX',
            'locality' => 'Austin',
            'address_lines' => ['123 Main St'],
        ]);

        $run = app(CrossAssetWebsiteGbpAddressConsistencyService::class)->analyze($website);

        $this->assertSame('missing_website_address', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_gbp_address_skips_honestly(): void
    {
        [$website, $gbp] = $this->pairedAssets();
        $this->seedWebsitePageHtml($website, [[
            'street_address' => '123 Main St',
            'locality' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'formatted' => '123 Main St, Austin, TX, 78701, US',
        ]]);
        $this->seedGbpLocationAccess($gbp, null);

        $run = app(CrossAssetWebsiteGbpAddressConsistencyService::class)->analyze($website);

        $this->assertSame('missing_gbp_address', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rejects_non_website_primary_asset(): void
    {
        $asset = DigitalAsset::factory()->create([
            'type' => 'google_business_profile',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(CrossAssetWebsiteGbpAddressConsistencyService::class)->analyze($asset);
    }

    /**
     * @return array{0: DigitalAsset, 1: DigitalAsset}
     */
    private function pairedAssets(): array
    {
        $brand = Brand::factory()->create();

        return [
            DigitalAsset::factory()->create([
                'brand_id' => $brand->id,
                'type' => 'website',
                'primary_url' => 'https://acme.example',
            ]),
            DigitalAsset::factory()->create([
                'brand_id' => $brand->id,
                'type' => 'google_business_profile',
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function seedWebsitePageHtml(DigitalAsset $website, array $addresses): Evidence
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
                'telephone_candidates' => [],
                'postal_address_candidates' => $addresses,
            ],
            'observed_at' => now(),
        ]);
    }

    /**
     * @param  array{
     *     region_code: string|null,
     *     postal_code: string|null,
     *     administrative_area: string|null,
     *     locality: string|null,
     *     address_lines: list<string>
     * }|null  $storefrontAddress
     */
    private function seedGbpLocationAccess(DigitalAsset $gbp, ?array $storefrontAddress): Evidence
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
            'payload' => [
                'requested_location_name' => 'locations/123',
                'location_name' => 'locations/123',
                'title' => 'Acme Local',
                'website_uri' => 'https://acme.example',
                'primary_phone' => '+1 555-0100',
                'primary_category' => 'Coffee shop',
                'storefront_address' => $storefrontAddress,
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
            ],
            'observed_at' => now(),
        ]);
    }
}
