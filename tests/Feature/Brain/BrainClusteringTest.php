<?php

namespace Tests\Feature\Brain;

use App\Ai\Agents\Brain\ClusterLabelAgent;
use App\Enums\DigitalAssetStatus;
use App\Models\BrainProposal;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\BrainRefresher;
use App\Services\Brain\Clustering\QueryIntent;
use App\Services\Brain\Proposals\Kinds\ClusterTargetsKind;
use App\Services\Brain\Proposals\Kinds\ServiceClustersKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Brain phase 2: page-sized clusters, cluster → page targets and cannibalization, without any AI. */
final class BrainClusteringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCatalogItem $implant;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        DB::table('brand_offerings')->insert(['brand_id' => $brand->id, 'status' => 'active', 'service_catalog_item_id' => $this->implant->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'domain' => 'atlas.test', 'primary_url' => 'https://atlas.test']);
    }

    public function test_intent_rules(): void
    {
        $this->assertSame('transactional', QueryIntent::of('implant fiyatları'));
        $this->assertSame('local', QueryIntent::of('kadıköy implant'));
        $this->assertSame('informational', QueryIntent::of('implant ağrılı mı'));
        $this->assertSame('commercial', QueryIntent::of('en iyi implant markası'));
    }

    public function test_queries_become_page_sized_clusters_targets_and_cannibalization(): void
    {
        foreach (['implant fiyatları', 'implant fiyat', 'kadıköy implant', 'implant ağrılı mı', 'implant ağrısı', 'implant sonrası beslenme', 'implant sonrası yemek'] as $text) {
            $this->attach($text);
        }
        // Our own site ranks one URL for both pain queries: the same-page signal that joins them.
        $this->gsc('implant ağrılı mı', '/blog/implant-agri', 40, 6);
        $this->gsc('implant ağrısı', '/blog/implant-agri', 30, 7);
        // The main topic is split between two pages (cannibalization).
        $this->gsc('implant fiyatları', '/implant', 300, 5);
        $this->gsc('implant fiyat', '/blog/implant-fiyat', 200, 8);

        $this->assertSame(1, app(ServiceClustersKind::class)->prepare(['service_id' => $this->implant->id]));
        $proposal = BrainProposal::query()->where('kind', 'service_clusters')->firstOrFail();
        $clusters = collect($proposal->proposed['clusters']);
        $clusterOf = fn (string $text): ?array => $clusters->first(fn (array $c): bool => in_array($this->id($text), $c['query_ids'], true));

        $this->assertSame($clusterOf('implant fiyatları')['key'], $clusterOf('kadıköy implant')['key'], 'place words are ignored');
        $this->assertSame('main', $clusterOf('implant fiyatları')['page_type']);
        $this->assertSame($clusterOf('implant ağrılı mı')['key'], $clusterOf('implant ağrısı')['key'], 'joined by the shared ranking URL');
        $this->assertNotSame($clusterOf('implant ağrılı mı')['key'], $clusterOf('implant fiyatları')['key']);
        $this->assertSame('informational', $clusterOf('implant ağrısı')['intent']);
        $this->assertSame('system', $proposal->source, 'no AI configured: algorithm names stay');

        app(ProposalService::class)->approve([$proposal->id], $this->admin);

        $this->assertSame(0, DB::table('search_query_library_item_service')->whereNull('library_cluster_id')->count());
        $main = DB::table('library_query_clusters')->where('page_type', 'main')->first();
        $this->assertSame('brain', $main->source);

        app(ClusterTargetsKind::class)->prepare([]);
        $target = BrainProposal::query()->where('kind', 'cluster_targets')->where('proposed->cluster_id', $main->id)->firstOrFail();
        $this->assertSame('https://atlas.test/implant', $target->proposed['url']);
        $this->assertSame(0.6, $target->confidence, '300 of 500 impressions');
        app(ProposalService::class)->approve([$target->id], $this->admin);
        $this->assertSame('https://atlas.test/implant', DB::table('library_cluster_targets')->where('cluster_key', $main->id)->value('url'));

        $report = app(BrainRefresher::class)->run();
        $this->assertGreaterThanOrEqual(1, $report['cannibalization']);
        $row = DB::table('brain_cannibalizations')->where('cluster_id', $main->id)->first();
        $this->assertNotNull($row, 'two pages split the main cluster 60 / 40 on page one');
        $this->assertEqualsWithDelta(0.4, (float) $row->second_share, 0.001);

        $this->actingAs($this->admin)->get(route('operator.brain.services', ['service' => $this->implant->id]))
            ->assertOk()->assertSee('Hizmet haritası')->assertSee('Ana hizmet sayfası')->assertSee('atlas.test/implant')->assertSee('sayfa bölüşüyor');
    }

    public function test_ai_names_clusters_but_cannot_add_a_second_main_page(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        foreach (['implant fiyatları', 'implant fiyat', 'implant sonrası beslenme', 'implant sonrası yemek', 'implant sonrası bakım'] as $text) {
            $this->attach($text);
        }
        ClusterLabelAgent::fake(function ($prompt): array {
            $input = json_decode(Str::after((string) $prompt, "INPUT_JSON\n"), true);

            return ['items' => array_map(fn (array $c): array => ['key' => $c['key'], 'name' => str_contains($c['head'], 'sonra') ? 'İmplant Sonrası Bakım' : 'İmplant Tedavisi',
                'page_type' => 'main', 'intent' => $c['intent'], 'reason' => 'test'], $input['clusters'])];
        });

        app(ServiceClustersKind::class)->prepare(['service_id' => $this->implant->id]);

        $proposal = BrainProposal::query()->where('kind', 'service_clusters')->firstOrFail();
        $this->assertSame('ai', $proposal->source);
        $clusters = collect($proposal->proposed['clusters'])->keyBy('name');
        $this->assertSame('main', $clusters['İmplant Tedavisi']['page_type']);
        $this->assertSame('support', $clusters['İmplant Sonrası Bakım']['page_type'], 'only one main page per service');
    }

    private function attach(string $text): void
    {
        SearchQueryLibraryItem::query()->create([
            'uuid' => (string) Str::uuid(), 'identity_hash' => hash('sha256', $text), 'canonical_text' => $text, 'folded_text' => SeoText::fold($text),
            'sector' => 'saglik', 'status' => 'active', 'is_branded' => false, 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ])->services()->attach($this->implant->id, ['is_primary' => true, 'provenance' => 'operator']);
    }

    private function id(string $text): int
    {
        return (int) SearchQueryLibraryItem::query()->where('canonical_text', $text)->value('id');
    }

    private function gsc(string $query, string $path, int $impressions, float $position): void
    {
        DB::table('gsc_query_page_daily')->insert([
            'digital_asset_id' => $this->site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:atlas.test', 'reporting_date' => now()->subDays(10)->toDateString(),
            'query' => $query, 'page' => 'https://atlas.test'.$path, 'clicks' => 3, 'impressions' => $impressions, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $query.$path),
            'metadata' => json_encode(['provider_average_position' => $position]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
