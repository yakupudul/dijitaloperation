<?php

namespace Tests\Feature\IntelligenceProjection;

use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Faz 0: a rebuild that reads no page never wipes the existing page inventory, a rebuild with pages still prunes
 * the ones that disappeared, and a brandless website is skipped instead of failing the queue.
 */
final class WebsiteProjectionPruneSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rebuild_with_zero_pages_keeps_existing_profiles(): void
    {
        $site = $this->site();
        $this->profile($site, 'https://prune.test/tedavilerimiz/implant-tedavisi/');
        $this->profile($site, 'https://prune.test/iletisim/');

        $run = app(WebsiteProjectionRebuilder::class)->rebuild($site, 'test');

        $this->assertSame(WebsiteIntelligenceProjectionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(0, data_get($run->summary, 'profile_counts.pages'));
        $this->assertSame(2, WebsitePageProfile::query()->where('website_asset_id', $site->id)->count(), 'empty result must not delete the inventory');
    }

    public function test_a_rebuild_with_pages_still_prunes_pages_that_disappeared(): void
    {
        $site = $this->site();
        $this->profile($site, 'https://prune.test/eski-sayfa/');
        DB::table('website_url')->insert([
            'digital_asset_id' => $site->id, 'asset_id' => (string) $site->id, 'normalized_url' => 'https://prune.test/yeni-sayfa/',
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'yeni'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(WebsiteProjectionRebuilder::class)->rebuild($site, 'test');

        $urls = WebsitePageProfile::query()->where('website_asset_id', $site->id)->pluck('preferred_url')->all();
        $this->assertSame(['https://prune.test/yeni-sayfa/'], $urls);
    }

    public function test_brandless_website_is_skipped_without_an_exception(): void
    {
        $site = $this->site();
        $this->profile($site, 'https://prune.test/iletisim/');
        Brand::query()->whereKey($site->brand_id)->first()?->delete();

        $asset = DigitalAsset::query()->findOrFail($site->id);
        $this->assertNull(app(WebsiteProjectionRebuilder::class)->rebuild($asset, 'test'));
        (new RebuildWebsiteProjectionJob($site->id))->handle(app(WebsiteProjectionRebuilder::class));

        $this->assertSame(0, WebsiteIntelligenceProjectionRun::query()->where('website_asset_id', $site->id)->where('trigger', '!=', 'fixture')->count());
        $this->assertSame(1, WebsitePageProfile::query()->where('website_asset_id', $site->id)->count());
    }

    private function site(): DigitalAsset
    {
        $brand = Brand::factory()->create();

        return DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'prune.test', 'primary_url' => 'https://prune.test/']);
    }

    private function profile(DigitalAsset $site, string $url): void
    {
        $identity = IntelligencePageIdentity::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $site->id,
            'identity_hash' => hash('sha256', $site->id.':'.$url), 'preferred_url' => $url,
            'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => 'prune.test', 'path' => (string) parse_url($url, PHP_URL_PATH),
            'resolution_status' => 'resolved', 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $site->id, 'trigger' => 'fixture', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);
        WebsitePageProfile::query()->create([
            'website_asset_id' => $site->id, 'page_identity_id' => $identity->id, 'projection_run_id' => $projection->id,
            'preferred_url' => $url, 'profile_version' => 1, 'projected_at' => now(), 'last_observed_at' => now(),
            'source_states' => ['website' => ['url' => $url]],
        ]);
    }
}
