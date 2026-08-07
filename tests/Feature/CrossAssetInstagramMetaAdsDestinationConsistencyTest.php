<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\CrossAssetInstagramMetaAdsDestinationConsistencyService;
use App\Services\InstagramAccountProfileCollectService;
use App\Services\MetaAdsAdDestinationUrlsCollectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossAssetInstagramMetaAdsDestinationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mismatch_upserts_finding_on_instagram_with_pack_fingerprint(): void
    {
        [$instagram, $meta] = $this->pairedAssets();

        $this->seedInstagramProfile($instagram, 'https://www.acme.example/');
        $this->seedMetaDestinationUrls($meta, [
            'https://other-landing.example/campaign',
            'https://www.acme.example/ok',
        ]);

        $service = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class);
        $firstRun = $service->analyze($instagram);
        $secondRun = $service->analyze($instagram);

        $this->assertSame('completed', $firstRun->status);
        $this->assertSame(CrossAssetInstagramMetaAdsDestinationConsistencyService::MODULE_ID, $firstRun->module_id);
        $this->assertTrue($firstRun->metadata['compared'] ?? false);
        $this->assertFalse($firstRun->metadata['hosts_match'] ?? true);
        $this->assertArrayHasKey('skip_reason', $firstRun->metadata);
        $this->assertNull($firstRun->metadata['skip_reason']);

        $comparison = Evidence::query()
            ->where('run_id', $firstRun->id)
            ->where('type', CrossAssetInstagramMetaAdsDestinationConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertSame('www.acme.example', $comparison->payload['instagram_host']);
        $this->assertContains('other-landing.example', $comparison->payload['meta_destination_url_hosts']);
        $this->assertFalse($comparison->payload['hosts_match']);
        $this->assertSame($meta->id, $comparison->payload['related_digital_asset_id']);

        $expectedFingerprint = hash('sha256', implode('|', [
            CrossAssetInstagramMetaAdsDestinationConsistencyService::PACK_ID,
            'primary_asset_id='.$instagram->id,
            'related_asset_id='.$meta->id,
        ]));

        $findings = Finding::query()
            ->where('digital_asset_id', $instagram->id)
            ->where('fingerprint', $expectedFingerprint)
            ->get();

        $this->assertCount(1, $findings);
        $finding = $findings->first();
        $this->assertSame('cross-channel', $finding->category);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame('open', $finding->status);
        $this->assertSame(CrossAssetInstagramMetaAdsDestinationConsistencyService::MODULE_ID, $finding->source_module);
        $this->assertSame($secondRun->id, $finding->last_run_id);
        $this->assertStringContainsString((string) $meta->id, (string) $finding->summary);

        $this->assertSame(1, Recommendation::query()->where('finding_id', $finding->id)->count());
        $this->assertSame(0, Finding::query()->where('digital_asset_id', $meta->id)->count());
    }

    public function test_matching_hosts_create_comparison_evidence_without_finding(): void
    {
        [$instagram, $meta] = $this->pairedAssets();

        $this->seedInstagramProfile($instagram, 'https://acme.example/about');
        $this->seedMetaDestinationUrls($meta, [
            'https://acme.example/a',
            'https://acme.example/b',
        ]);

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertTrue($run->metadata['compared'] ?? false);
        $this->assertTrue($run->metadata['hosts_match'] ?? false);
        $this->assertSame(0, Finding::query()->count());

        $comparison = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', CrossAssetInstagramMetaAdsDestinationConsistencyService::EVIDENCE_TYPE_COMPARISON)
            ->first();

        $this->assertNotNull($comparison);
        $this->assertTrue($comparison->payload['hosts_match']);
    }

    public function test_missing_meta_ads_asset_skips_without_finding(): void
    {
        $brand = Brand::factory()->create();
        $instagram = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'instagram',
        ]);
        $this->seedInstagramProfile($instagram, 'https://solo.example');

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertSame('missing_meta_ads_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertFalse($run->metadata['compared'] ?? true);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_ambiguous_meta_ads_assets_skip_without_inventing_pair(): void
    {
        $brand = Brand::factory()->create();
        $instagram = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'instagram',
        ]);
        DigitalAsset::factory()->count(2)->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
        ]);
        $this->seedInstagramProfile($instagram, 'https://acme.example');

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertSame('ambiguous_meta_ads_asset', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_instagram_profile_evidence_skips_honestly(): void
    {
        [$instagram, $meta] = $this->pairedAssets();
        $this->seedMetaDestinationUrls($meta, ['https://acme.example/ads']);

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertSame('missing_instagram_account_profile_evidence', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
        $this->assertNotNull($instagram->id);
    }

    public function test_missing_instagram_website_skips_without_finding(): void
    {
        [$instagram, $meta] = $this->pairedAssets();
        $this->seedInstagramProfile($instagram, null);
        $this->seedMetaDestinationUrls($meta, ['https://acme.example/ads']);

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertSame('missing_instagram_website', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_missing_meta_destination_evidence_skips_honestly(): void
    {
        [$instagram, $meta] = $this->pairedAssets();
        $this->seedInstagramProfile($instagram, 'https://acme.example');

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertSame('missing_meta_ads_ad_destination_urls_evidence', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
        $this->assertNotNull($meta->id);
    }

    public function test_empty_meta_destination_hosts_skips_without_finding(): void
    {
        [$instagram, $meta] = $this->pairedAssets();
        $this->seedInstagramProfile($instagram, 'https://acme.example');
        $this->seedMetaDestinationUrls($meta, []);

        $run = app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($instagram);

        $this->assertSame('missing_meta_ads_destination_url_hosts', $run->metadata['skip_reason'] ?? null);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rejects_non_instagram_asset(): void
    {
        $meta = DigitalAsset::factory()->create(['type' => 'meta_ads']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('instagram Digital Asset');

        app(CrossAssetInstagramMetaAdsDestinationConsistencyService::class)->analyze($meta);
    }

    /**
     * @return array{0: DigitalAsset, 1: DigitalAsset}
     */
    private function pairedAssets(): array
    {
        $brand = Brand::factory()->create();
        $instagram = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'instagram',
        ]);
        $meta = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
        ]);

        return [$instagram, $meta];
    }

    private function seedInstagramProfile(DigitalAsset $instagram, ?string $website): void
    {
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
                'requested_ig_user_id' => '17841400000000000',
                'ig_user_id' => '17841400000000000',
                'username' => 'acme_brand',
                'name' => 'Acme Brand',
                'account_type' => 'BUSINESS',
                'website' => $website,
                'biography' => 'Hello',
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'instagram_graph_ig_user_get',
            ],
        ]);
    }

    /**
     * @param  list<string>  $destinationUrls
     */
    private function seedMetaDestinationUrls(DigitalAsset $meta, array $destinationUrls): void
    {
        $hosts = [];
        foreach ($destinationUrls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        $run = Run::factory()->create([
            'digital_asset_id' => $meta->id,
            'module_id' => MetaAdsAdDestinationUrlsCollectService::MODULE_ID,
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $meta->id,
            'source_module' => MetaAdsAdDestinationUrlsCollectService::MODULE_ID,
            'type' => MetaAdsAdDestinationUrlsCollectService::EVIDENCE_TYPE_AD_DESTINATION_URLS,
            'payload' => [
                'requested_ad_account_id' => 'act_1111111111',
                'destination_urls' => $destinationUrls,
                'destination_url_hosts' => array_values(array_unique($hosts)),
                'destination_url_count' => count($destinationUrls),
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'meta_ads_ads_list_get',
            ],
        ]);
    }
}
