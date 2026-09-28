<?php

namespace Tests\Feature\Demand;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Portfolio\BrandDemand;
use App\Livewire\Operator\Portfolio\BrandQueryHubPanel;
use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandQueryPortfolioItem;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\EmbeddingService;
use App\Services\Brain\ServiceProfiles;
use App\Services\Demand\BrandDemandBuilder;
use App\Services\Demand\BrandQueryHub;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Brain\InsertsFacts;
use Tests\TestCase;

final class BrandQueryHubTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $siteA;

    private DigitalAsset $siteB;

    private ServiceCatalogItem $implantService;

    private ServiceCatalogItem $orthoService;

    private ServiceCatalogItem $whiteningService;

    private ServiceCatalogItem $lawService;

    private BrandOffering $implant;

    private BrandOffering $ortho;

    private BrandOffering $whitening;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $health = ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        ServiceCategory::query()->firstOrCreate(['normalized_key' => 'hukuk'], ['code' => 'hukuk', 'name' => 'Hukuk']);
        $catalog = app(ServiceCatalogService::class);
        $this->implantService = $catalog->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        $this->orthoService = $catalog->resolveOrCreate('Ortodonti', 'saglik', actor: $this->admin)['service'];
        $this->whiteningService = $catalog->resolveOrCreate('Diş Beyazlatma', 'saglik', actor: $this->admin)['service'];
        $this->lawService = $catalog->resolveOrCreate('Boşanma Davası', 'hukuk', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->append($this->implantService, ['implant']);
        app(ServiceKeywordService::class)->append($this->lawService, ['boşanma']);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental', 'sector' => null]);
        $this->brand->sectors()->attach($health->id);
        $this->siteA = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        $this->siteB = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasortodonti.com/', 'domain' => 'atlasortodonti.com']);
        $this->implant = $this->offer($this->implantService);
        $this->ortho = $this->offer($this->orthoService);
        $this->whitening = $this->offer($this->whiteningService);
    }

    public function test_sources_merge_by_folded_query_with_sites_trend_volume_and_serp(): void
    {
        $this->gsc($this->siteA, 'İmplant Fiyatları', 10, 200, daysAgo: 5, position: 4.0);
        $this->gsc($this->siteB, 'implant fiyatları', 2, 100, daysAgo: 40, position: 10.0);
        $this->ads('implant fiyatları', clicks: 4, conversions: 1);
        $this->gbp('IMPLANT FIYATLARI', 30);
        $library = $this->libraryQuery('implant fiyatları');
        DB::table('search_query_library_source_records')->insert([
            'search_query_library_item_id' => $library->id, 'source_fingerprint' => hash('sha256', 'kp-1'), 'source_type' => 'keyword_planner',
            'observed_text' => 'implant fiyatları', 'search_volume' => 1300, 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->portfolio('implant fiyatları', $library);
        DB::table('demand_serp_checks')->insert([
            'brand_id' => $this->brand->id, 'keyword' => 'implant fiyatları', 'location_code' => 2792, 'language_code' => 'tr',
            'fingerprint' => hash('sha256', 'serp-1'), 'status' => 'completed', 'our_rank' => 7, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $stats = app(BrandDemandBuilder::class)->build($this->brand);

        $this->assertSame(1, $stats['queries'], 'one row per folded query across every source');
        $row = BrandDemandQuery::query()->where('brand_id', $this->brand->id)->sole();
        $this->assertEqualsCanonicalizing(['search_console', 'google_ads', 'google_business_profile', 'portfolio', 'area_serp'], $row->sources);
        $this->assertSame(300, (int) $row->gsc_impressions);
        $this->assertSame(30, (int) $row->gbp_impressions);
        $this->assertEqualsWithDelta((4.0 * 200 + 10.0 * 100) / 300, $row->gsc_position, 0.01, 'impression-weighted position');
        $this->assertSame(250, (int) $row->recent_impressions, 'last 28 days: GSC 200 + Ads 50');
        $this->assertSame(100, (int) $row->previous_impressions);
        $this->assertSame('rising', $row->trend());
        $this->assertSame(1300, (int) $row->search_volume);
        $this->assertSame(7, (int) $row->serp_rank);
        $this->assertSame($library->id, $row->search_query_library_item_id);
        $this->assertSame(now()->subDays(40)->toDateString(), $row->first_observed_on->toDateString());
        $this->assertSame(2, $row->assets()->count(), 'site-specific Search Console metrics per website');

        $hub = app(BrandQueryHub::class);
        $forA = $hub->rowsFor($this->brand, $this->siteA);
        $this->assertCount(1, $forA);
        $this->assertSame(200, $forA[0]['site']['impressions']);
        $this->assertSame($this->implant->id, $forA[0]['offering_id']);
        $this->assertSame($this->implantService->id, $forA[0]['catalog_service_id']);
        $this->assertSame('saglik', $forA[0]['sector']);
        $this->assertSame('transactional', $forA[0]['intent']);
        $this->assertSame('İmplant Tedavisi', $forA[0]['offering_name']);
        $this->assertCount(1, $hub->rowsFor($this->brand, null, ['source' => 'google_ads']));
        $this->assertCount(0, $hub->rowsFor($this->brand, null, ['source' => 'competitor']));

        // A query seen only on site B is left out of site A's rows; brand-wide rows (Ads only) stay in.
        $this->gsc($this->siteB, 'şeffaf plak', 1, 20, daysAgo: 3);
        $this->ads('diş beyazlatma fiyatı', clicks: 1, conversions: 0);
        $this->travel(1)->seconds();
        app(BrandDemandBuilder::class)->build($this->brand);
        $queriesA = array_column($hub->rowsFor($this->brand, $this->siteA), 'query');
        $this->assertNotContains('şeffaf plak', $queriesA);
        $this->assertContains('diş beyazlatma fiyatı', $queriesA);
    }

    public function test_brand_level_service_resolution_tiers_and_relevance(): void
    {
        config(['moxdop.openai.api_key' => 'sk-test', 'moxdop.anthropic.api_key' => 'sk-ant-test']);
        $this->gsc($this->siteA, 'ankara implant fiyatı', 3, 60);
        $this->libraryQuery('gülüş estetiği')->services()->attach($this->whiteningService->id, ['is_primary' => true, 'provenance' => 'brain_review']);
        $this->gsc($this->siteA, 'gülüş estetiği', 2, 40);
        $this->portfolio('tel tedavisi')->services()->attach($this->orthoService->id, ['provenance' => 'operator']);
        $this->gsc($this->siteA, 'çapraşık dişler', 1, 30);
        $this->gsc($this->siteA, 'hava durumu yarın', 0, 10);
        $this->gsc($this->siteA, 'boşanma avukatı ücreti', 0, 10);
        $this->gsc($this->siteA, 'atlasdis yorumlar', 5, 50);
        DB::table('query_exclusion_rules')->insert(['label' => 'bedava', 'normalized' => 'bedava', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->gsc($this->siteA, 'bedava diş muayenesi', 0, 10);
        $this->cacheEmbeddings(['çapraşık dişler' => [0.0, 1.0, 0.0]]);

        app(BrandDemandBuilder::class)->build($this->brand);
        $rows = BrandDemandQuery::query()->where('brand_id', $this->brand->id)->get()->keyBy('query');

        $this->assertSame([$this->implant->id, 'rule'], [$rows['implant fiyatı']->brand_offering_id, $rows['implant fiyatı']->assignment_method], 'the place name is stripped: core query');
        $this->assertSame([$this->whitening->id, 'library'], [$rows['gülüş estetiği']->brand_offering_id, $rows['gülüş estetiği']->assignment_method]);
        $this->assertSame(0.8, $rows['gülüş estetiği']->assignment_confidence);
        $this->assertSame([$this->ortho->id, 'portfolio'], [$rows['tel tedavisi']->brand_offering_id, $rows['tel tedavisi']->assignment_method]);
        $this->assertSame([$this->ortho->id, 'embedding'], [$rows['çapraşık dişler']->brand_offering_id, $rows['çapraşık dişler']->assignment_method]);
        $this->assertNull($rows['hava durumu yarın']->brand_offering_id);
        $this->assertSame(BrandDemandQuery::UNCLEAR, $rows['hava durumu yarın']->relevance, 'no service, no sector evidence: belirsiz');
        $this->assertSame(BrandDemandQuery::IRRELEVANT, $rows['boşanma avukatı ücreti']->relevance, 'another sector: alakasız');
        $this->assertSame('hukuk', $rows['boşanma avukatı ücreti']->sector);
        $this->assertArrayNotHasKey('bedava diş muayenesi', $rows->all(), 'excluded expression: banned list, not a hub query');
        $this->assertSame('banned', DB::table('query_variants')->where('raw_text', 'bedava diş muayenesi')->value('kind'));
        $this->assertTrue($rows['yorumlar']->is_branded, 'own domain stripped; only seen with the brand: branded');
        $this->assertSame(BrandDemandQuery::RELEVANT, $rows['yorumlar']->relevance);
        $this->assertSame(BrandDemandQuery::RELEVANT, $rows['tel tedavisi']->relevance);

        $defaultRows = array_column(app(BrandQueryHub::class)->rowsFor($this->brand), 'query');
        $this->assertNotContains('boşanma avukatı ücreti', $defaultRows, 'alakasız rows are kept but not handed to the next phase by default');
        $this->assertCount(7, app(BrandQueryHub::class)->rowsFor($this->brand, null, ['relevance' => 'all']));
    }

    public function test_manual_review_survives_rebuild(): void
    {
        $this->gsc($this->siteA, 'implant fiyatları', 10, 200);
        $this->gsc($this->siteA, 'hava durumu', 1, 20);
        $this->gsc($this->siteA, 'diş sararması', 1, 20);
        $this->gsc($this->siteA, 'gülüş tasarımı', 1, 20);
        app(BrandDemandBuilder::class)->build($this->brand);
        $ids = BrandDemandQuery::query()->pluck('id', 'query');
        $hub = app(BrandQueryHub::class);

        $hub->assign($this->brand, [$ids['implant fiyatları'], $ids['diş sararması']], $this->whitening, $this->admin);
        $hub->markIrrelevant($this->brand, [$ids['hava durumu']], $this->admin);
        $hub->confirm($this->brand, [$ids['gülüş tasarımı']], $this->admin);
        $this->travel(1)->seconds();
        app(BrandDemandBuilder::class)->build($this->brand);

        $rows = BrandDemandQuery::query()->get()->keyBy('query');
        $this->assertSame($this->whitening->id, $rows['implant fiyatları']->brand_offering_id, 'operator wins over the rule match');
        $this->assertSame('operator', $rows['implant fiyatları']->assignment_method);
        $this->assertSame(BrandDemandQuery::IRRELEVANT, $rows['hava durumu']->relevance);
        $this->assertSame(BrandDemandQuery::RELEVANT, $rows['gülüş tasarımı']->relevance, 'confirmed: relevant without a service');
        $this->assertSame(BrandDemandQuery::SOURCE_OPERATOR, $rows['gülüş tasarımı']->assignment_source);
        $this->assertSame($this->admin->id, $rows['hava durumu']->reviewed_by);
    }

    public function test_passive_customer_brand_is_skipped(): void
    {
        $this->gsc($this->siteA, 'implant fiyatları', 10, 200);
        $this->brand->customer->update(['status' => CustomerStatus::Inactive]);

        $stats = app(BrandDemandBuilder::class)->build($this->brand->fresh());

        $this->assertTrue($stats['skipped']);
        $this->assertSame(0, BrandDemandQuery::query()->count());

        $this->actingAs($this->admin);
        Livewire::test(BrandDemand::class, ['brandId' => $this->brand->id])->call('rebuild')->assertSee('Hizmet kapsamı dışında');
        $this->assertSame(0, BrandDemandQuery::query()->count());
    }

    public function test_panel_filters_counts_and_bulk_actions(): void
    {
        $this->actingAs($this->admin);
        $this->gsc($this->siteA, 'implant fiyatları', 10, 200);
        $this->gsc($this->siteA, 'diş sararması', 1, 20);
        $this->gsc($this->siteA, 'hava durumu', 1, 20);
        $this->ads('boşanma avukatı', clicks: 1, conversions: 0);
        app(BrandDemandBuilder::class)->build($this->brand);
        $ids = BrandDemandQuery::query()->pluck('id', 'query');

        $component = Livewire::test(BrandQueryHubPanel::class, ['brandId' => $this->brand->id])
            ->assertSee('Sorgu merkezi')
            ->assertSee('implant fiyatları')
            ->assertSee('İmplant Tedavisi')
            ->assertDontSee('boşanma avukatı')
            ->set('relevance', 'unclear')
            ->assertSee('diş sararması')
            ->assertDontSee('implant fiyatları')
            ->set('relevance', 'irrelevant')
            ->assertSee('boşanma avukatı')
            ->set('relevance', '')
            ->set('source', 'google_ads')
            ->assertDontSee('diş sararması')
            ->set('source', '')
            ->set('selected', [(string) $ids['diş sararması']])
            ->set('bulkOffering', (string) $this->whitening->id)
            ->call('bulkAssign')
            ->assertSee('1 sorgu');

        $this->assertSame($this->whitening->id, BrandDemandQuery::query()->find($ids['diş sararması'])->brand_offering_id);
        $this->assertSame(BrandDemandQuery::SOURCE_OPERATOR, BrandDemandQuery::query()->find($ids['diş sararması'])->assignment_source);

        $component->set('selected', [(string) $ids['hava durumu']])->call('bulkIrrelevant');
        $this->assertSame(BrandDemandQuery::IRRELEVANT, BrandDemandQuery::query()->find($ids['hava durumu'])->relevance);

        $component->call('showService', (string) $this->whitening->id)->assertSee('diş sararması')->assertDontSee('implant fiyatları');

        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'business']))->assertOk()->assertSee('Sorgu merkezi');
    }

    private function offer(ServiceCatalogItem $service): BrandOffering
    {
        return BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active']);
    }

    private function libraryQuery(string $text): SearchQueryLibraryItem
    {
        $canonical = app(SearchTermNormalizer::class)->normalize($text, 'tr')->canonicalText;

        return SearchQueryLibraryItem::query()->create([
            'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', 'library-location-free-v2|'.$canonical), 'canonical_text' => $canonical,
            'folded_text' => SeoText::fold($text), 'sector' => 'saglik', 'status' => 'active', 'is_branded' => false,
            'normalization_version' => 'library_location_free_v2', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function portfolio(string $text, ?SearchQueryLibraryItem $library = null): BrandQueryPortfolioItem
    {
        return BrandQueryPortfolioItem::query()->create([
            'uuid' => (string) Str::uuid(), 'brand_id' => $this->brand->id, 'search_query_library_item_id' => $library?->id,
            'identity_hash' => hash('sha256', 'brand:'.$this->brand->id.'|'.$text), 'custom_canonical_text' => $library === null ? $text : null,
            'custom_folded_text' => $library === null ? SeoText::fold($text) : null, 'area_scope' => 'all_brand_areas',
            'origin_type' => $library === null ? 'brand_custom' : 'global_inherited', 'status' => 'active', 'global_proposal_status' => 'not_applicable',
        ]);
    }

    /**
     * Cached vectors only: each offered service's profile texts get a one-hot vector (implant, ortho, whitening).
     *
     * @param  array<string, list<float>>  $queries
     */
    private function cacheEmbeddings(array $queries): void
    {
        $provider = app(EmbeddingService::class)->provider();
        $this->assertNotNull($provider, 'an embedding provider route is configured');
        $axis = [$this->implantService->id => [1.0, 0.0, 0.0], $this->orthoService->id => [0.0, 1.0, 0.0], $this->whiteningService->id => [0.0, 0.0, 1.0]];
        $texts = $queries;
        foreach (app(ServiceProfiles::class)->all()->only(array_keys($axis)) as $id => $profile) {
            foreach (ServiceProfiles::texts($profile) as $text) {
                $texts[$text] = $axis[$id];
            }
        }
        foreach ($texts as $text => $vector) {
            DB::table('brain_embeddings')->insertOrIgnore([
                'text_hash' => hash('sha256', trim(SeoText::fold($text))), 'model' => $provider[1], 'dimensions' => 3,
                'vector' => json_encode($vector), 'created_at' => now(),
            ]);
        }
    }

    private function gsc(DigitalAsset $site, string $query, int $clicks, int $impressions, int $daysAgo = 5, ?float $position = null): void
    {
        $this->insertFacts('gsc_query_daily', [
            'digital_asset_id' => $site->id, 'site_url' => 'sc-domain:'.$site->domain, 'reporting_date' => now()->subDays($daysAgo)->toDateString(),
            'query' => $query, 'clicks' => $clicks, 'impressions' => $impressions, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => $position !== null ? json_encode(['provider_average_position' => $position]) : null,
        ]);
    }

    private function ads(string $term, int $clicks, int $conversions): void
    {
        DB::table('google_ads_search_term_daily')->insert([
            'digital_asset_id' => $this->siteA->id, 'customer_id' => '123', 'reporting_date' => now()->subDays(3)->toDateString(),
            'search_term' => $term, 'impressions' => 50, 'clicks' => $clicks, 'cost_micros' => 1_000_000, 'conversions' => $conversions,
            'cost_amount' => 1, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20),
        ]);
    }

    private function gbp(string $keyword, int $impressions): void
    {
        DB::table('gbp_search_keywords_monthly')->insert([
            'digital_asset_id' => $this->siteA->id, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1',
            'month_start' => now()->startOfMonth()->toDateString(), 'search_keyword' => $keyword, 'search_keyword_hash' => hash('sha256', $keyword),
            'impressions' => $impressions, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
