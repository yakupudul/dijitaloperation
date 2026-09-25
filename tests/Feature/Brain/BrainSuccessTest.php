<?php

namespace Tests\Feature\Brain;

use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\Success\PageFeatureExtractor;
use App\Services\Brain\Success\SuccessScorer;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Brain phase 4: comparable success scores inside a cohort, and measured page facts incl. cluster coverage. */
final class BrainSuccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCatalogItem $implant;

    private int $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        $this->cluster = DB::table('library_query_clusters')->insertGetId(['service_id' => $this->implant->id, 'name' => 'İmplant Tedavisi', 'name_key' => 'implant tedavisi', 'status' => 'active', 'page_type' => 'main', 'source' => 'brain', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['implant fiyatları', 'implant tedavisi', 'implant ağrısı'] as $text) {
            SearchQueryLibraryItem::query()->create([
                'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', $text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
                'sector' => 'saglik', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
            ])->services()->attach($this->implant->id, ['is_primary' => true, 'provenance' => 'operator', 'library_cluster_id' => $this->cluster]);
        }
    }

    public function test_pages_are_scored_within_their_cohort_with_thin_data_pulled_to_the_average(): void
    {
        $strong = $this->site('Güçlü', 3000, 240, 2.0);
        $middle = $this->site('Orta', 1500, 60, 5.0);
        $weak = $this->site('Zayıf', 400, 2, 14.0);
        $tiny = $this->site('Yeni', 10, 5, 3.0);

        $written = app(SuccessScorer::class)->run($this->implant->id);

        $this->assertSame(4, $written);
        $scores = DB::table('brain_success_snapshots')->pluck('score', 'digital_asset_id');
        $this->assertGreaterThan($scores[$middle->id], $scores[$strong->id]);
        $this->assertGreaterThan($scores[$weak->id], $scores[$middle->id]);
        $tinyRow = DB::table('brain_success_snapshots')->where('digital_asset_id', $tiny->id)->first();
        $this->assertLessThan(0.5, (float) $tinyRow->ctr_shrunk, '5 clicks of 10 impressions is pulled strongly toward the cohort average');
        $this->assertSame(4, (int) $tinyRow->cohort_size);
        $this->assertStringEndsWith(':main:unknown', (string) $tinyRow->cohort_key);

        $this->actingAs($this->admin)->get(route('operator.brain.services', ['service' => $this->implant->id]))
            ->assertOk()->assertSee('Sayfalar ve başarı')->assertSee('Başarı '.(int) $scores[$strong->id]);
    }

    public function test_page_facts_include_cluster_coverage(): void
    {
        $site = $this->site('Atlas', 100, 5, 4.0);
        $html = '<html><head><title>İmplant Tedavisi</title><script type="application/ld+json">{"@type":"FAQPage"}</script></head><body><h1>İmplant Tedavisi</h1>'
            .'<p>İmplant tedavisi eksik dişler için yapılır. İmplant fiyatları muayene sonrası belirlenir.</p><h2>Süreç nasıl?</h2><h2>Kimler için?</h2><h2>Ne kadar sürer?</h2></body></html>';

        $features = app(PageFeatureExtractor::class)->fromHtml($site, 'https://atlas.test/implant', $html, ['implant fiyatlari' => true, 'implant tedavisi' => true, 'implant agrisi' => true]);

        $this->assertEqualsWithDelta(0.667, $features['coverage'], 0.001, 'the page answers 2 of the 3 cluster queries (no "ağrı")');
        $this->assertTrue($features['faq']);
        $this->assertTrue($features['medical_schema']);
        $this->assertSame(1, DB::table('brain_page_features')->where('digital_asset_id', $site->id)->count());
    }

    private function site(string $name, int $impressions, int $clicks, float $position): DigitalAsset
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => $name]);
        DB::table('brand_offerings')->insert(['brand_id' => $brand->id, 'status' => 'active', 'service_catalog_item_id' => $this->implant->id, 'created_at' => now(), 'updated_at' => now()]);
        $domain = Str::slug($name).'.test';
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'domain' => $domain, 'primary_url' => 'https://'.$domain]);
        DB::table('gsc_query_page_daily')->insert([
            'digital_asset_id' => $site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:'.$domain, 'reporting_date' => now()->subDays(10)->toDateString(),
            'query' => 'implant fiyatları', 'page' => 'https://'.$domain.'/implant', 'clicks' => $clicks, 'impressions' => $impressions, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $domain),
            'metadata' => json_encode(['provider_average_position' => $position]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $site;
    }
}
