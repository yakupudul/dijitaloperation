<?php

namespace Tests\Feature\Brain;

use App\Ai\Agents\Brain\CreativeClassifierAgent;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Brain\RecommendationsPage;
use App\Models\BrainProposal;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\Chain\AdsChainBuilder;
use App\Services\Brain\Chain\MetaChainBuilder;
use App\Services\Brain\Proposals\Kinds\MetaAdServicesKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Brain phase 3: ad group ↔ cluster ↔ page chain with its recommendations, and Meta ads filed under services. */
final class BrainChainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCatalogItem $implant;

    private Brand $brand;

    /** @var array<string, int> */
    private array $clusters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        app(ServiceKeywordService::class)->append($this->implant, ['implant']);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        DB::table('brand_offerings')->insert(['brand_id' => $this->brand->id, 'status' => 'active', 'service_catalog_item_id' => $this->implant->id, 'created_at' => now(), 'updated_at' => now()]);
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'domain' => 'atlas.test']);
        foreach (['main' => ['İmplant Tedavisi', ['implant fiyat', 'implant fiyatları']], 'support' => ['İmplant Ağrısı', ['implant ağrısı', 'implant ağrılı mı']], 'landing' => ['All on Four', ['all on four']]] as $type => [$name, $queries]) {
            $id = DB::table('library_query_clusters')->insertGetId(['service_id' => $this->implant->id, 'name' => $name, 'name_key' => SeoText::fold($name), 'status' => 'active', 'page_type' => $type, 'source' => 'brain', 'created_at' => now(), 'updated_at' => now()]);
            $this->clusters[$type] = $id;
            foreach ($queries as $text) {
                SearchQueryLibraryItem::query()->create([
                    'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', $text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
                    'sector' => 'saglik', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
                ])->services()->attach($this->implant->id, ['is_primary' => true, 'provenance' => 'operator', 'library_cluster_id' => $id]);
            }
        }
        DB::table('library_cluster_targets')->insert(['service_id' => $this->implant->id, 'cluster_key' => $this->clusters['main'], 'digital_asset_id' => $site->id, 'url' => 'https://atlas.test/implant', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_ad_groups_are_mapped_to_clusters_and_recommendations_follow_the_one_cluster_one_page_rule(): void
    {
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $keyword = fn (string $group, string $text, int $impressions, ?int $qs = null): array => ['ad_group_id' => $group, 'criterion_id' => $text, 'campaign_id' => 'C1', 'text' => $text,
            'match_type' => 'PHRASE', 'status' => 'ENABLED', 'quality_score' => $qs, 'cost' => $impressions / 10, 'clicks' => 5, 'impressions' => $impressions, 'conversions' => 1.0];
        $data = [
            'bound' => true,
            'keywords' => [$keyword('AG1', 'implant fiyat', 200, 5), $keyword('AG1', 'implant ağrısı', 80), $keyword('AG2', 'implant ağrılı mı', 90, 8)],
            'search_terms' => ['implant ağrısı' => ['term' => 'implant ağrısı', 'cost' => 20.0, 'clicks' => 4, 'impressions' => 40, 'conversions' => 0.0, 'ad_group_ids' => ['AG1'], 'campaign_ids' => ['C1'], 'pmax' => false]],
            'ads' => ['ad_groups' => ['AG1' => 'İmplant Genel', 'AG2' => 'İmplant Ağrı'], 'items' => [
                ['ad_id' => '1', 'status' => 'ENABLED', 'final_urls' => ['https://atlas.test/fiyat'], 'ad_group_id' => 'AG1'],
                ['ad_id' => '2', 'status' => 'ENABLED', 'final_urls' => ['https://atlas.test/blog/agri'], 'ad_group_id' => 'AG2'],
            ]],
        ];

        $this->assertSame(2, app(AdsChainBuilder::class)->store($ads, $data));

        $ag1 = DB::table('brain_ad_group_clusters')->where('ad_group_id', 'AG1')->first();
        $this->assertSame($this->clusters['main'], (int) $ag1->cluster_id, 'AG1 is mostly the main topic');
        $this->assertFalse((bool) $ag1->url_matches, 'it sends people to /fiyat, not to the main page');
        $types = DB::table('brain_recommendations')->where('status', 'open')->pluck('type')->all();
        $this->assertContains('ads_split_ad_group', $types, 'AG1 serves the main and the pain topic');
        $this->assertContains('ads_final_url', $types);
        $this->assertContains('ads_cross_cluster_terms', $types, 'AG1 pays for a term the AG2 topic owns');
        $this->assertContains('ads_missing_cluster', $types, 'the "all on four" buying topic has no ad group');

        // Re-running keeps one open row per finding; a dismissed one is not raised again; a fixed one closes by itself.
        $split = DB::table('brain_recommendations')->where('type', 'ads_split_ad_group')->first();
        Livewire::actingAs($this->admin)->test(RecommendationsPage::class)->call('dismiss', $split->id);
        $data['keywords'][1]['text'] = 'implant fiyatları';
        app(AdsChainBuilder::class)->store($ads, $data);
        $this->assertSame(1, DB::table('brain_recommendations')->where('type', 'ads_final_url')->where('status', 'open')->count());
        $this->assertSame('dismissed', DB::table('brain_recommendations')->where('id', $split->id)->value('status'));
        $this->assertSame(0, DB::table('brain_recommendations')->where('type', 'ads_split_ad_group')->where('status', 'open')->count());

        $this->actingAs($this->admin)->get(route('operator.brain.recommendations'))->assertOk()->assertSee('Beyin önerileri')->assertSee('İmplant Genel');
        $this->actingAs($this->admin)->get(route('operator.brain.services', ['service' => $this->implant->id]))->assertOk()->assertSee('Google Ads reklam grupları')->assertSee('farklı sayfa');
    }

    public function test_meta_ads_are_filed_under_services_by_rule_then_ai(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        $meta = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'status' => DigitalAssetStatus::Active]);
        $day = now()->subDays(3)->toDateString();
        $data = [
            'bound' => true, 'period' => ['start' => now()->subDays(30)->toDateString()],
            'ads' => [
                'A1' => ['name' => 'İmplant ekim video', 'adset_id' => 'S1', 'campaign_id' => 'K1', 'creative_id' => 'CR1', 'daily' => [$day => ['spend' => 100.0, 'impressions' => 1000, 'clicks' => 20, 'results' => 5.0]]],
                'A2' => ['name' => 'Gülüşünüz', 'adset_id' => 'S1', 'campaign_id' => 'K1', 'creative_id' => 'CR2', 'daily' => [$day => ['spend' => 50.0, 'impressions' => 500, 'clicks' => 5, 'results' => 1.0]]],
            ],
            'adsets' => ['S1' => ['name' => 'Kadıköy 30-55']], 'campaigns' => ['K1' => ['name' => 'Ekim']],
            'creatives' => ['CR1' => ['object_type' => 'VIDEO', 'body' => 'Tek seansta'], 'CR2' => ['object_type' => 'PHOTO', 'body' => 'Eksik dişlerinizi tamamlayın']],
        ];

        $this->assertSame(2, app(MetaChainBuilder::class)->store($meta, $data));
        $rows = DB::table('brain_meta_ads')->get()->keyBy('ad_id');
        $this->assertSame($this->implant->id, (int) $rows['A1']->service_id);
        $this->assertSame('rule', $rows['A1']->source);
        $this->assertSame('video', $rows['A1']->format);
        $this->assertNull($rows['A2']->service_id, 'no service word: left for AI');

        CreativeClassifierAgent::fake([['items' => [
            ['ad_id' => 'A1', 'service_id' => $this->implant->id, 'angle' => 'education', 'reason' => 'süreç'],
            ['ad_id' => 'A2', 'service_id' => $this->implant->id, 'angle' => 'result_benefit', 'reason' => 'sonuç'],
        ]]]);
        $this->assertSame(1, app(MetaAdServicesKind::class)->prepare([]));
        $proposal = BrainProposal::query()->where('kind', 'meta_ad_services')->firstOrFail();
        app(ProposalService::class)->approve([$proposal->id], $this->admin);

        $a2 = DB::table('brain_meta_ads')->where('ad_id', 'A2')->first();
        $this->assertSame($this->implant->id, (int) $a2->service_id);
        $this->assertSame('result_benefit', $a2->angle);

        // A later rule run does not overwrite an approved choice.
        app(MetaChainBuilder::class)->store($meta, $data);
        $this->assertSame('ai', DB::table('brain_meta_ads')->where('ad_id', 'A2')->value('source'));
        $this->actingAs($this->admin)->get(route('operator.brain.services', ['service' => $this->implant->id]))->assertOk()->assertSee('Sonuç / fayda');
    }
}
