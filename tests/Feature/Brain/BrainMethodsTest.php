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
use App\Services\Brain\Methods\GapRecommender;
use App\Services\Brain\Methods\MetaAngleRecommender;
use App\Services\Brain\Methods\MethodEngine;
use App\Services\Brain\Methods\MethodValidator;
use App\Services\Brain\Methods\OutcomeMeasurer;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Brain phase 5: method found from the cohort → gap recommendation → measured against controls → validated. */
final class BrainMethodsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCatalogItem $implant;

    private int $main;

    private int $support;

    /** @var list<DigitalAsset> */
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-brain.methods.min_pages' => 6, 'moxdop-brain.methods.min_brands' => 6, 'moxdop-brain.validation.min_treated' => 1]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        $this->main = $this->cluster('İmplant Tedavisi', 'main', ['implant fiyatları', 'implant tedavisi']);
        $this->support = $this->cluster('İmplant Sonrası', 'support', ['implant sonrası beslenme', 'implant sonrası ağrı', 'implant sonrası bakım']);
        $period = now()->startOfMonth()->toDateString();
        foreach (range(1, 9) as $i) {
            $site = $this->site('Marka '.$i);
            $this->sites[] = $site;
            $url = 'https://'.$site->domain.'/implant';
            DB::table('library_cluster_targets')->insert(['service_id' => $this->implant->id, 'cluster_key' => $this->main, 'digital_asset_id' => $site->id, 'url' => $url, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $successful = $i <= 3;
            DB::table('brain_success_snapshots')->insert([
                'period' => $period, 'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'service_id' => $this->implant->id, 'cluster_id' => $this->main,
                'page_type' => 'main', 'market_tier' => 'unknown', 'cohort_key' => 'x', 'cohort_size' => 9, 'url' => $url, 'impressions' => 1000 - $i * 50, 'clicks' => 10,
                'score' => 100 - $i * 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('brain_page_features')->insert([
                'digital_asset_id' => $site->id, 'url' => $url, 'url_key' => SeoText::urlKey($url), 'content_hash' => 'h'.$i,
                'features' => json_encode(['faq' => $successful || $i === 5, 'words' => $successful ? 1500 : 400, 'coverage' => 0.5]),
                'ai_features' => json_encode(['expert_shown' => $i % 2 === 0]), 'ai_hash' => 'h'.$i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_methods_are_found_recommended_measured_and_validated(): void
    {
        $this->assertGreaterThanOrEqual(1, app(MethodEngine::class)->discover());

        $faq = DB::table('brain_methods')->where('feature', 'faq')->first();
        $this->assertNotNull($faq, 'all 3 top pages have an FAQ, 1 of 3 bottom pages');
        $this->assertSame('hypothesis', $faq->status);
        $this->assertNotNull(DB::table('brain_methods')->where('feature', 'words')->first(), 'numeric: top pages ≥ 1500 words');
        $this->assertNull(DB::table('brain_methods')->where('feature', 'expert_shown')->first(), 'present in top and bottom alike → not a method');
        $this->assertStringNotContainsString('Marka', (string) $faq->evidence, 'no brand is named in a method');

        app(GapRecommender::class)->run();

        $weak = $this->sites[8];
        $rec = DB::table('brain_recommendations')->where('digital_asset_id', $weak->id)->where('type', 'website_method:faq')->first();
        $this->assertNotNull($rec);
        $this->assertSame('observational', $rec->basis);
        $this->assertSame((int) $faq->id, (int) $rec->method_id);
        $this->assertSame(0, DB::table('brain_recommendations')->where('digital_asset_id', $this->sites[0]->id)->where('type', 'website_method:faq')->count(), 'the top page already has it');
        $this->assertSame(9, DB::table('brain_recommendations')->where('type', 'website_create_page')->where('cluster_id', $this->support)->count(), 'no site has a page for the support topic');

        // Applied 60 days ago: the treated site's clicks tripled while a similar untreated site stayed flat.
        $resolved = now()->subDays(60)->startOfDay();
        DB::table('brain_recommendations')->where('id', $rec->id)->update(['status' => 'done', 'resolved_at' => $resolved]);
        $this->gsc($weak, 'implant fiyatları', $resolved->copy()->subDays(10), 10);
        $this->gsc($weak, 'implant fiyatları', $resolved->copy()->addDays(10), 30);
        $this->gsc($weak, 'implant tedavisi', $resolved->copy()->addDays(40), 30);
        $control = $this->sites[7];
        $this->gsc($control, 'implant fiyatları', $resolved->copy()->subDays(10), 10);
        $this->gsc($control, 'implant fiyatları', $resolved->copy()->addDays(10), 11);
        $this->gsc($control, 'implant tedavisi', $resolved->copy()->addDays(40), 11);

        $this->assertSame(2, app(OutcomeMeasurer::class)->measureDue(), 'day 28 and day 56');
        $outcome = json_decode((string) DB::table('brain_recommendations')->where('id', $rec->id)->value('outcome'), true);
        $this->assertSame('measured', $outcome['d56']['status']);
        $this->assertGreaterThan(0.5, $outcome['d56']['effect'], 'treated rose far more than the control');
        $this->assertStringContainsString('56. gün', $outcome['summary']);

        app(MethodValidator::class)->run();
        $this->assertSame('validated', DB::table('brain_methods')->where('id', $faq->id)->value('status'));

        app(GapRecommender::class)->run();
        $this->assertSame('validated', DB::table('brain_recommendations')->where('type', 'website_method:faq')->where('status', 'open')->where('digital_asset_id', $this->sites[7]->id)->value('basis'));

        $this->actingAs($this->admin)->get(route('operator.brain.methods'))->assertOk()->assertSee('Etkisi kanıtlandı')->assertSee('Sayfada SSS bölümü var');
    }

    public function test_meta_message_angle_that_works_across_brands_is_recommended_to_brands_without_it(): void
    {
        foreach (array_slice($this->sites, 0, 4) as $i => $site) {
            $meta = DigitalAsset::factory()->create(['brand_id' => $site->brand_id, 'type' => 'meta_ads', 'status' => DigitalAssetStatus::Active]);
            $angles = $i < 3 ? ['result_benefit' => [100, 20], 'price_offer' => [100, 5]] : ['price_offer' => [100, 4]];
            foreach ($angles as $angle => [$spend, $results]) {
                DB::table('brain_meta_ads')->insert(['digital_asset_id' => $meta->id, 'brand_id' => $site->brand_id, 'ad_id' => $angle.$i, 'service_id' => $this->implant->id,
                    'angle' => $angle, 'spend' => $spend, 'results' => $results, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        app(MetaAngleRecommender::class)->run();

        $this->assertNotNull(DB::table('brain_methods')->where('channel', 'meta_ads')->where('feature', 'angle:result_benefit')->first());
        $rec = DB::table('brain_recommendations')->where('type', 'meta_try_angle')->get();
        $this->assertCount(1, $rec, 'only the brand without the angle gets it');
        $this->assertSame($this->sites[3]->brand_id, (int) $rec[0]->brand_id);
    }

    /** @param  list<string>  $queries */
    private function cluster(string $name, string $type, array $queries): int
    {
        $id = DB::table('library_query_clusters')->insertGetId(['service_id' => $this->implant->id, 'name' => $name, 'name_key' => SeoText::fold($name), 'status' => 'active', 'page_type' => $type, 'source' => 'brain', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($queries as $text) {
            SearchQueryLibraryItem::query()->create([
                'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', $text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
                'sector' => 'saglik', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
            ])->services()->attach($this->implant->id, ['is_primary' => true, 'provenance' => 'operator', 'library_cluster_id' => $id]);
        }

        return $id;
    }

    private function site(string $name): DigitalAsset
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => $name]);
        DB::table('brand_offerings')->insert(['brand_id' => $brand->id, 'status' => 'active', 'service_catalog_item_id' => $this->implant->id, 'created_at' => now(), 'updated_at' => now()]);
        $domain = Str::slug($name).'.test';

        return DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'domain' => $domain, 'primary_url' => 'https://'.$domain]);
    }

    private function gsc(DigitalAsset $site, string $query, \DateTimeInterface $date, int $clicks): void
    {
        DB::table('gsc_query_page_daily')->insert([
            'digital_asset_id' => $site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:'.$site->domain, 'reporting_date' => $date->format('Y-m-d'),
            'query' => $query, 'page' => 'https://'.$site->domain.'/implant', 'clicks' => $clicks, 'impressions' => $clicks * 20, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $site->id.$query.$date->format('Y-m-d')),
            'metadata' => json_encode(['provider_average_position' => 4]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
