<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\CrossAssetWebsiteInstagramWebsiteUrlConsistencyService;
use App\Services\InstagramAccountProfileCollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossAssetWebsiteInstagramWebsiteUrlConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mismatch_upserts_finding_on_website_with_pack_fingerprint(): void
    {
        [$website, $instagram] = $this->pairedAssets();

        $this->seedWebsiteHttpFetch($website, 'https://www.acme.example/');
        $this->seedInstagramProfile($instagram, 'https://other-social.example/');

        $service = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class);
        $firstRun = $service->analyze($website);
        $secondRun = $service->analyze($website);

        $this->assertSame('completed', $firstRun->status);
        $this->assertSame(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::MODULE_ID, $firstRun->module_id);
        $this->assertTrue($firstRun->metadata['compared'] ?? false);
        $this->assertFalse($firstRun->metadata['hosts_match'] ?? true);
        $this->assertNull($firstRun->metadata['skip_reason']);

        $comparison = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertSame('www.acme.example', $comparison->payload['website_host']);
        $this->assertSame('other-social.example', $comparison->payload['instagram_host']);
        $this->assertFalse($comparison->payload['hosts_match']);
        $this->assertSame($instagram->id, $comparison->payload['related_digital_asset_id']);

        $expectedFingerprint = hash('sha256', implode('|', [
            CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::PACK_ID,
            'primary_asset_id='.$website->id,
            'related_asset_id='.$instagram->id,
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
        $this->assertSame(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::MODULE_ID, $finding->source_module);
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertStringContainsString((string) $instagram->id, (string) $finding->summary);

        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $instagram->id)->count());
    }

    public function test_matching_hosts_create_comparison_evidence_without_finding(): void
    {
        [$website, $instagram] = $this->pairedAssets();

        $this->seedWebsiteHttpFetch($website, 'https://acme.example/about');
        $this->seedInstagramProfile($instagram, 'https://acme.example');

        $run = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertTrue($run->metadata['compared'] ?? false);
        $this->assertTrue($run->metadata['hosts_match'] ?? false);
        $this->assertSame(0, Finding::query()->count());

        $comparison = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertTrue($comparison->payload['hosts_match']);
    }

    public function test_missing_instagram_asset_skips_without_finding(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://solo.example',
        ]);
        $this->seedWebsiteHttpFetch($website, 'https://solo.example');

        $run = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_instagram_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertFalse($run->metadata['compared'] ?? true);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_ambiguous_instagram_assets_skip_without_inventing_pair(): void
    {
        $brand = Brand::factory()->create();
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://acme.example',
        ]);
        DigitalAsset::factory()->count(2)->create([
            'brand_id' => $brand->id,
            'type' => 'instagram',
        ]);
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');

        $run = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('ambiguous_instagram_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_instagram_profile_evidence_skips_honestly(): void
    {
        [$website, $instagram] = $this->pairedAssets();
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');

        $run = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_instagram_account_profile_evidence', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
        $this->assertNotNull($instagram->id);
    }

    public function test_missing_instagram_website_skips_without_finding(): void
    {
        [$website, $instagram] = $this->pairedAssets();
        $this->seedWebsiteHttpFetch($website, 'https://acme.example');
        $this->seedInstagramProfile($instagram, null);

        $run = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_instagram_website', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rejects_non_website_asset(): void
    {
        $instagram = DigitalAsset::factory()->create(['type' => 'instagram']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('website Digital Asset');

        app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($instagram);
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
        $instagramOtherBrand = DigitalAsset::factory()->create([
            'brand_id' => $brandB->id,
            'type' => 'instagram',
        ]);

        $this->seedWebsiteHttpFetch($website, 'https://acme.example');
        $this->seedInstagramProfile($instagramOtherBrand, 'https://other.example');

        $run = app(CrossAssetWebsiteInstagramWebsiteUrlConsistencyService::class)->analyze($website);

        $this->assertSame('missing_instagram_asset', $run->metadata['skip_reason'] ?? null);
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
            'primary_url' => 'https://www.acme.example',
        ]);
        $instagram = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'instagram',
        ]);

        return [$website, $instagram];
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

    private function seedInstagramProfile(DigitalAsset $instagram, ?string $website): void
    {
        $host = null;
        if (is_string($website) && $website !== '') {
            $parsed = parse_url($website, PHP_URL_HOST);
            $host = is_string($parsed) ? strtolower($parsed) : null;
        }

        $run = Run::factory()->create([
            'digital_asset_id' => $instagram->id,
            'module_id' => InstagramAccountProfileCollectService::MODULE_ID,
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $instagram->id,
            'source_module' => InstagramAccountProfileCollectService::MODULE_ID,
            'type' => InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE,
            'payload' => [
                'requested_ig_user_id' => '17841400000000001',
                'ig_user_id' => '17841400000000001',
                'username' => 'acme_brand',
                'name' => 'Acme Brand',
                'account_type' => 'BUSINESS',
                'website' => $website,
                'website_host' => $host,
                'biography' => 'Bio',
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'instagram_graph_ig_user_get',
            ],
        ]);
    }
}
