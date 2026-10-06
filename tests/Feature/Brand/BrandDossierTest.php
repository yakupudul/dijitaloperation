<?php

namespace Tests\Feature\Brand;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Workspace\BrandDossierTab;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandMemory;
use App\Models\Cluster;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\ResourceAutomation;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** Marka dosyası: one short file per brand, compiled without AI; section hashes tell an agent what changed. */
final class BrandDossierTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Ada Klinik'])->id, 'name' => 'Adadent']);
    }

    public function test_it_compiles_every_section_and_reports_only_what_changed(): void
    {
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://adadent.test', 'domain' => 'adadent.test']);
        $dossier = app(BrandDossier::class);
        $first = $dossier->build($this->brand);

        $this->assertSame(array_keys(BrandDossier::SECTIONS), array_keys($first['sections']));
        $this->assertStringContainsString('# Adadent', $first['markdown']);
        $this->assertStringContainsString('Müşteri: Ada Klinik', $first['markdown']);
        $this->assertStringContainsString('https://adadent.test', $first['markdown']);
        $this->assertSame(1, BrandMemory::query()->where('brand_id', $this->brand->id)->where('kind', BrandDossier::KIND)->count());

        $seen = array_map(fn (array $s): string => $s['hash'], $first['sections']);
        $this->assertSame([], BrandDossier::changedSince($this->brand, $seen), 'nothing new: nothing to read');

        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin);
        BrandDossier::saveNotes($this->brand, 'Implant hastası artsın', 'Fiyat yazma');
        $second = $dossier->build($this->brand);

        $this->assertSame(['services', 'demand', 'notes'], BrandDossier::changedSince($this->brand, $seen));
        $this->assertStringContainsString('İmplant Tedavisi', $second['sections']['services']['markdown']);
        $this->assertStringContainsString('Fiyat yazma', $second['sections']['notes']['markdown']);
        $this->assertSame(1, BrandMemory::query()->where('brand_id', $this->brand->id)->where('kind', BrandDossier::KIND)->count(), 'one row, rebuilt in place');
        $this->assertSame(array_keys(BrandDossier::SECTIONS), BrandDossier::changedSince(Brand::factory()->create(), []), 'never built: everything is new');
    }

    public function test_the_tab_shows_the_file_and_the_notes_read_only_and_rebuilds(): void
    {
        BrandDossier::saveNotes($this->brand, 'Kadıköy şubesi öne çıksın', 'Rakip adı geçmesin');

        Livewire::test(BrandDossierTab::class, ['brandId' => $this->brand->id])
            ->assertSee('Bilgi dosyası')->assertSeeHtml('data-dossier-section="identity"')->assertSeeHtml('data-dossier-section="context"')->assertSee('Ada Klinik')
            ->assertSeeHtml('data-dossier-notes')->assertSee('Rakip adı geçmesin')->assertDontSeeHtml('wire:submit="saveNotes"')
            ->assertSee(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'ayarlar']), false)
            ->call('rebuild')->assertSee('Bilgi dosyası yenilendi');

        $this->assertSame(['goals' => 'Kadıköy şubesi öne çıksın', 'constraints' => 'Rakip adı geçmesin'], BrandDossier::notes($this->brand));
        $this->assertArrayHasKey('dosya', BrandShow::TABS);
        $this->assertSame('Bilgi dosyası', BrandShow::TABS['dosya']);
    }

    public function test_goals_typed_only_in_the_older_business_context_are_read_until_the_notes_are_saved(): void
    {
        BrandIntelligenceContext::withLegacyIdentityProjection(fn () => BrandIntelligenceContext::query()->create(['brand_id' => $this->brand->id,
            'business_goals' => [['goal' => 'Nitelikli randevu']], 'important_constraints' => 'Fiyat yazma', 'business_summary' => 'Kadıköy diş kliniği',
            'target_audiences' => [['name' => 'Yetişkinler']], 'conversion_goals' => [['label' => 'WhatsApp']]]));

        $this->assertSame(['goals' => 'Nitelikli randevu', 'constraints' => 'Fiyat yazma'], BrandDossier::notes($this->brand));
        $file = app(BrandDossier::class)->build($this->brand);
        $this->assertStringContainsString('Özet: Kadıköy diş kliniği', $file['sections']['context']['markdown']);
        $this->assertStringContainsString('Hedef kitle: Yetişkinler', $file['sections']['context']['markdown']);
        $this->assertStringContainsString('Dönüşüm hedefleri: WhatsApp', $file['sections']['context']['markdown']);
        $this->assertStringContainsString('Nitelikli randevu', $file['sections']['notes']['markdown']);

        BrandDossier::saveNotes($this->brand, 'Implant artsın', '');
        $this->assertSame(['goals' => 'Implant artsın', 'constraints' => ''], BrandDossier::notes($this->brand), 'saved notes are the one source');
    }

    public function test_the_file_lists_every_page_of_a_service_the_clusters_behind_each_state_and_why_work_is_open(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://adadent.test', 'domain' => 'adadent.test']);
        $implant = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin)['offering'];
        $implant->forceFill(['priority' => 'main'])->save();
        foreach (['/implant/mini-implant/', '/implant/', '/implant/all-on-4/'] as $i => $path) {
            $page = Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://adadent.test'.$path, 'url_hash' => hash('sha256', $path), 'path' => $path]);
            OfferingPage::query()->create(['brand_offering_id' => $implant->id, 'page_id' => $page->id, 'source' => 'rule']);
        }
        $sector = ServiceCategory::query()->firstOrCreate(['code' => 'saglik'], ['name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $service = app(ServiceCatalogService::class)->resolveOrCreate('Zirkonyum', 'saglik', actor: $this->admin)['service'];
        foreach ([['İmplant fiyatları', 'insufficient_data'], ['Ankara implant', 'insufficient_data'], ['Zirkonyum kaplama', 'thin_coverage'], ['Diş beyazlatma', 'sufficient']] as [$name, $state]) {
            $cluster = Cluster::query()->create(['sector_id' => $sector->id, 'service_id' => $service->id, 'name' => $name, 'approved' => true]);
            BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $site->id, 'state' => $state, 'language' => 'tr']);
        }
        for ($i = 1; $i <= 7; $i++) {
            Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => $i <= 4 ? 'maps' : 'search', 'title' => 'İş '.$i, 'reason' => 'Neden '.$i.' çünkü',
                'status' => Suggestion::OPEN, 'priority' => $i, 'decision_key' => 'test', 'action_type' => 'test',
                'fingerprint' => str_pad((string) $i, 64, 'a'), 'material_hash' => str_repeat('b', 64)]);
        }

        $file = app(BrandDossier::class)->build($this->brand);

        $this->assertStringContainsString('★ İmplant Tedavisi → /implant/, /implant/all-on-4/, /implant/mini-implant/', $file['sections']['services']['markdown'], 'every page, the hub first');
        $site = $file['sections']['site']['markdown'];
        $this->assertStringContainsString('Veri yetersiz: Ankara implant, İmplant fiyatları', $site);
        $this->assertStringContainsString('Kapsam yetersiz: Zirkonyum kaplama', $site);
        $this->assertStringNotContainsString('Yeterli: Diş beyazlatma', $site);
        $open = $file['sections']['open']['markdown'];
        $this->assertStringContainsString('Toplam 7 açık iş · Harita 4, Arama 3 (en acil 5 tanesi aşağıda)', $open);
        $this->assertStringContainsString('- [Harita] İş 1 — Neden 1 çünkü', $open);
    }

    public function test_the_nightly_command_builds_operational_brands(): void
    {
        $this->artisan('moxdop:brands:dossier')->assertSuccessful();
        $built = BrandMemory::query()->where('kind', BrandDossier::KIND)->pluck('brand_id')->all();
        $this->assertSame(Brand::query()->operational()->pluck('id')->all(), $built);
    }

    public function test_a_business_profile_reads_its_last_data_day_from_the_stored_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
        $profile = $this->boundAccount($this->brand, 'google_business_profile', ['collection_status' => 'current', 'data_through' => null]);
        $this->insertFact('gbp_performance_daily', ['external_resource_id' => $profile->id, 'digital_asset_id' => null, 'reporting_date' => '2026-10-03',
            'metric' => 'CALL_CLICKS', 'run_id' => 1, 'location_name' => 'locations/1', 'value' => 2, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $section = app(BrandDossier::class)->build($this->brand)['sections']['assets']['markdown'];

        $this->assertStringContainsString('google_business_profile (son veri 2026-10-03)', $section);
        $this->assertStringNotContainsString('veri yok', $section);
        $accounts = app(BrandWorkspaceReadService::class)->assets($this->brand)[0]['accounts'];
        $this->assertTrue($accounts[0]['has_data'], 'the brand checklist counts stored rows as data too');
    }

    public function test_an_account_with_rows_but_a_stopped_collection_shows_both_and_one_without_rows_has_no_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
        $ads = $this->boundAccount($this->brand, 'google_ads', ['collection_status' => 'attention', 'collection_error' => 'collection_failed', 'data_through' => null]);
        $this->adsDay($ads, '2026-10-04', 120);
        $this->boundAccount($this->brand, 'search_console');
        $parked = $this->boundAccount($this->brand, 'google_ads', ['collection_status' => 'attention', 'collection_error' => 'not_enabled']);
        $this->adsDay($parked, '2026-10-01', 15);
        // Two failures out of three: the account waits for its own retry, so it is not a stop (ResourceAutomationService::fail).
        $this->boundAccount($this->brand, 'ga4', ['collection_status' => 'waiting', 'collection_error' => 'collection_failed']);
        $this->boundAccount($this->brand, 'meta_ads', ['collection_enabled' => false]);

        $section = app(BrandDossier::class)->build($this->brand)['sections']['assets']['markdown'];

        $this->assertStringContainsString('google_ads (son veri 2026-10-04 · çekim durdu: Son toplama başarısız)', $section);
        $this->assertStringContainsString('google_ads (son veri 2026-10-01 · çekim durdu: Google Ads hesabı etkin değil (kapalı ya da askıda))', $section);
        $this->assertStringContainsString('search_console (veri yok)', $section, 'no rows at all: veri yok');
        $this->assertStringContainsString('ga4 (veri yok · yeniden denenecek: Son toplama başarısız)', $section, 'a retry pending is not a stop');
        $this->assertStringContainsString('meta_ads (veri yok · otomatik çekim kapalı)', $section);
        $this->assertStringNotContainsString('collection_failed', $section, 'never the raw code');
    }

    public function test_a_google_ads_account_that_stopped_spending_says_so(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
        $ads = $this->boundAccount($this->brand, 'google_ads');
        $this->adsDay($ads, '2026-08-01', 40);
        $this->adsDay($ads, '2026-08-02', 0);
        $recent = $this->boundAccount($this->brand, 'google_ads');
        $this->adsDay($recent, '2026-10-04', 40);

        $section = app(BrandDossier::class)->build($this->brand)['sections']['assets']['markdown'];

        $this->assertStringContainsString('google_ads (son veri 2026-08-02 · hesap 2 aydır harcamasız (son harcama 2026-08-01) — doğru hesap bağlı mı?)', $section);
        $this->assertStringContainsString('google_ads (son veri 2026-10-04)', $section, 'spending account: no note');
        $this->assertSame(1, substr_count($section, 'harcamasız'));
    }

    public function test_the_assets_section_costs_the_same_queries_for_one_or_twenty_assets(): void
    {
        $small = Brand::factory()->create(['customer_id' => $this->brand->customer_id]);
        $large = Brand::factory()->create(['customer_id' => $this->brand->customer_id]);
        foreach ([[$small, 1], [$large, 20]] as [$brand, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->adsDay($this->boundAccount($brand, 'google_ads', ['collection_status' => 'attention', 'collection_error' => 'collection_failed']), now()->subDays(2)->toDateString(), 10);
            }
        }
        $assets = new ReflectionMethod(BrandDossier::class, 'assets');
        $dossier = app(BrandDossier::class);
        $assets->invoke($dossier, $small); // one-time schema checks are not per asset
        $queries = function (Brand $brand) use ($assets, $dossier): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $section = $assets->invoke($dossier, $brand);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertStringContainsString('çekim durdu', $section);

            return $count;
        };

        $this->assertSame($queries($small), $queries($large));
    }

    /**
     * An account bound to a new asset of the brand, with its automation.
     *
     * @param  array<string, mixed>  $automation
     */
    private function boundAccount(Brand $brand, string $type, array $automation = []): CoreExternalResource
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => in_array($type, ['search_console', 'ga4'], true) ? 'website' : $type,
            'primary_url' => null, 'domain' => null]);
        $resource = CoreExternalResource::factory()->create(['resource_type' => $type, 'external_id' => $type.'/'.fake()->unique()->numerify('##########')]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $type,
            'status' => CoreAssetBinding::STATUS_ACTIVE]);
        ResourceAutomation::query()->create($automation + ['external_resource_id' => $resource->id, 'collection_enabled' => true, 'collection_status' => 'current']);

        return $resource;
    }

    private function adsDay(CoreExternalResource $resource, string $date, float $cost): void
    {
        $this->insertFact('google_ads_account_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => (string) $resource->id, 'reporting_date' => $date,
            'impressions' => 100, 'clicks' => 5, 'cost_micros' => (int) ($cost * 1_000_000), 'cost_amount' => $cost, 'conversions' => 0,
            'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $resource->id.$date), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
