<?php

namespace Tests\Feature\SeoTasks;

use App\Enums\Collection\CollectionRunStatus;
use App\Jobs\CollectWebsiteInventoryJob;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Jobs\RunSeoPlanJob;
use App\Livewire\Operator\Seo\SeoTasksPanel;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ModuleRegistry;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\ServicePageAssignment;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use App\Services\SeoTasks\SeoInventoryGuard;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 0 — page inventory guarantee: a plan for a site with no page list queues a collection (or a projection
 * rebuild), proposes no new pages, shows one setup card and re-runs itself once the rebuilt projection has pages.
 */
final class SeoInventoryGuaranteeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-seo-tasks.llm.enabled' => false]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        ModuleRegistry::query()->updateOrCreate(['module_id' => 'website'], ['enabled' => true]);
    }

    public function test_empty_inventory_queues_a_collection_and_shows_a_setup_card_instead_of_create_tasks(): void
    {
        Bus::fake([CollectWebsiteInventoryJob::class, RebuildWebsiteProjectionJob::class, RunSeoPlanJob::class]);
        $site = $this->fixture();

        $plan = $this->runPlan($site);

        Bus::assertDispatched(CollectWebsiteInventoryJob::class, fn (CollectWebsiteInventoryJob $job): bool => $job->websiteAssetId === $site->id);
        $this->assertTrue(data_get($plan->input_summary, 'inventory.empty'));
        $this->assertSame('queued', data_get($plan->input_summary, 'inventory.collection'));

        $tasks = SeoTask::query()->where('digital_asset_id', $site->id)->get();
        $this->assertFalse($tasks->contains(fn (SeoTask $t): bool => $t->type->value === 'create'), 'no "Oluştur" task on an empty inventory');
        $card = $tasks->firstWhere('rule_id', SeoTaskRuleEngine::INVENTORY_MISSING_RULE);
        $this->assertNotNull($card);
        $this->assertStringContainsString('Sitenin sayfa listesi henüz yok — tarama başlatıldı; tarama bitince plan kendiliğinden yenilenir.', $card->reason);
        $this->assertSame(0, ServicePageAssignment::query()->where('digital_asset_id', $site->id)->count());

        Livewire::test(SeoTasksPanel::class, ['websiteId' => $site->id])
            ->assertSee('Sayfa listesi yok')
            ->assertSee('Sitenin sayfa listesi henüz yok')
            ->assertDontSee('Hizmet ↔ sayfa eşleşmeleri tamam');
    }

    public function test_an_active_collection_is_not_duplicated_and_a_recent_one_is_rebuilt_instead(): void
    {
        Bus::fake([CollectWebsiteInventoryJob::class, RebuildWebsiteProjectionJob::class, RunSeoPlanJob::class]);
        $site = $this->fixture();
        $guard = app(SeoInventoryGuard::class);

        $active = CollectionRun::factory()->create(['digital_asset_id' => $site->id, 'status' => CollectionRunStatus::Running]);
        $this->assertSame(['empty' => true, 'collection' => 'running', 'collection_run_id' => $active->id], $guard->ensure($site));
        Bus::assertNotDispatched(CollectWebsiteInventoryJob::class);

        $active->forceFill(['status' => CollectionRunStatus::Completed, 'finished_at' => now()])->save();
        $resource = CollectionResourceRun::factory()->create(['collection_run_id' => $active->id, 'digital_asset_id' => $site->id]);
        CollectionDatasetRun::factory()->create(['collection_run_id' => $active->id, 'collection_resource_run_id' => $resource->id,
            'provider_or_source' => 'WEBSITE_DIRECT', 'dataset_contract_id' => 'website_url', 'request_family_id' => 'WEB_RF_HTTP_HTML_DIAGNOSIS']);

        $state = $guard->ensure($site);
        $this->assertSame('rebuilding', $state['collection']);
        Bus::assertDispatched(RebuildWebsiteProjectionJob::class, fn (RebuildWebsiteProjectionJob $job): bool => $job->websiteAssetId === $site->id);
        Bus::assertNotDispatched(CollectWebsiteInventoryJob::class);
    }

    public function test_sitemap_rows_become_page_profiles_and_the_blocked_plan_reruns_with_the_service_page_covered(): void
    {
        Bus::fake([CollectWebsiteInventoryJob::class, RunSeoPlanJob::class]);
        $site = $this->fixture();
        $blocked = $this->runPlan($site);
        $this->assertTrue(data_get($blocked->input_summary, 'inventory.empty'));

        // The collection's sitemap step wrote only URLs (no HTML read yet).
        foreach (['/', '/tedavilerimiz/implant-tedavisi/', '/tedavilerimiz/ortodonti/', '/dis-beyazlatma-nedir/', '/iletisim/'] as $path) {
            $this->websiteUrl($site, 'https://panorama.test'.$path);
        }
        (new RebuildWebsiteProjectionJob(websiteAssetId: $site->id))->handle(app(WebsiteProjectionRebuilder::class));

        $profile = WebsitePageProfile::query()->where('website_asset_id', $site->id)->where('preferred_url', 'https://panorama.test/tedavilerimiz/implant-tedavisi/')->first();
        $this->assertNotNull($profile, 'sitemap URL alone produces a page profile');

        $rerun = SeoPlan::query()->where('digital_asset_id', $site->id)->latest('id')->firstOrFail();
        $this->assertNotSame($blocked->id, $rerun->id, 'the blocked plan is queued again after the projection');
        $this->assertSame(SeoInventoryGuard::TRIGGER_INVENTORY_READY, $rerun->trigger);
        Bus::assertDispatched(RunSeoPlanJob::class, fn (RunSeoPlanJob $job): bool => $job->planId === $rerun->id);

        $done = app(SeoPlanRunner::class)->run($rerun->id);
        $this->assertFalse((bool) data_get($done->input_summary, 'inventory.empty'));
        $tasks = SeoTask::query()->where('digital_asset_id', $site->id)->where('status', 'open')->get();
        $this->assertNull($tasks->firstWhere('rule_id', SeoTaskRuleEngine::INVENTORY_MISSING_RULE), 'setup card goes stale once pages exist');
        $this->assertFalse($tasks->contains(fn (SeoTask $t): bool => $t->title === 'İmplant Tedavisi için hizmet sayfası aç'));
        $create = $tasks->filter(fn (SeoTask $t): bool => $t->type->value === 'create');
        $this->assertNotEmpty($create);
        $this->assertTrue($create->every(fn (SeoTask $t): bool => ! str_contains((string) $t->target_url, '/blog/')));
        $this->assertTrue($create->every(fn (SeoTask $t): bool => preg_match('/fiyat|öncesi/iu', $t->title.' '.json_encode($t->content_brief, JSON_UNESCAPED_UNICODE)) === 0), 'dental brand: health pack wording');

        // A re-run that is itself blocked is not repeated (no loop).
        $done->forceFill(['input_summary' => array_merge($done->input_summary, ['inventory' => ['empty' => true, 'collection' => 'empty_after_crawl']])])->save();
        $this->assertNull(app(SeoInventoryGuard::class)->afterProjection($site));
    }

    private function runPlan(DigitalAsset $site): SeoPlan
    {
        $runner = app(SeoPlanRunner::class);

        return $runner->run($runner->queue($site, $this->admin)->id);
    }

    private function fixture(): DigitalAsset
    {
        $brand = Brand::factory()->create(['name' => 'Panorama Ağız ve Diş', 'sector' => 'dental']);
        $site = DigitalAsset::factory()->create([
            'brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'panorama.test',
            'primary_url' => 'https://panorama.test/', 'seo_market_language_code' => 'tr',
        ]);
        $offerings = app(BrandOfferingService::class);
        $implant = $offerings->create($brand, 'İmplant Tedavisi');
        $implant->forceFill(['is_priority' => true, 'priority_rank' => 1])->save();
        $offerings->create($brand, 'Ortodonti');

        return $site->fresh();
    }

    private function websiteUrl(DigitalAsset $site, string $url): void
    {
        DB::table('website_url')->insert([
            'digital_asset_id' => $site->id, 'asset_id' => (string) $site->id, 'normalized_url' => $url, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $url),
            'metadata' => json_encode(['source' => 'sitemap']), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
