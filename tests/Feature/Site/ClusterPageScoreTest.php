<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Library\QueriesPage;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryPipeline;
use App\Services\Site\ClusterPageScorer;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** Sayfa puanı (docs/product/CONTENT_IDEAS_BLUEPRINT.md §3) and "Bu kümeye atanmış sayfalar (tüm markalar)". */
final class ClusterPageScoreTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private ServiceCategory $dental;

    private ServiceCatalogItem $implant;

    #[Test]
    public function the_score_formula_follows_the_blueprint(): void
    {
        // Blueprint example: position 6.2, coverage 0.42, click rate 0.06 → 72.
        $this->assertSame(72, ClusterPageScorer::score(['position' => 6.2, 'coverage' => 0.42, 'ctr' => 0.06]));
        $this->assertSame(100, ClusterPageScorer::score(['position' => 1.0, 'coverage' => 0.9, 'ctr' => 0.2]));
        $this->assertSame(1, ClusterPageScorer::score(['position' => 45.0, 'coverage' => 0.0, 'ctr' => 0.0]), 'never 0');
    }

    #[Test]
    public function pages_are_scored_on_their_cluster_queries_and_shown_for_every_brand(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('Diş İmplantı', 'dental', actor: $admin)['service'];
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');

        [$izmir, $izmirSite, $gsc] = $this->brand('Klinik İzmir', 'klinik.test', true);
        [$ankara, $ankaraSite] = $this->brand('Klinik Ankara', 'ankara.test', false);
        foreach (['implant fiyatları' => 900, 'implant ücreti' => 300, 'implant sonrası ağrı' => 200, 'implant markaları' => 50, 'implant kaç yıl dayanır' => 10] as $raw => $impressions) {
            DB::table('query_sources')->insert(['external_resource_id' => $gsc->id, 'source' => 'gsc', 'raw_query' => $raw, 'month' => '2026-08-01',
                'impressions' => $impressions, 'clicks' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(QueryPipeline::class)->run(import: true);
        $cluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'İmplant fiyatı',
            'intent' => 'commercial', 'page_type' => 'service', 'approved' => true]);
        foreach (['implant fiyatları', 'implant ücreti', 'implant markaları'] as $text) {
            ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => Query::query()->where('text', $text)->value('id')]);
        }
        $suggested = Query::query()->create(['text' => 'implant fiyatı 2027', 'text_hash' => hash('sha256', 'x'), 'sector_id' => $this->dental->id, 'is_suggested' => true]);
        ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $suggested->id, 'is_suggested' => true]);
        $izmirPage = $this->page($izmirSite, 'https://klinik.test/implant/');
        $lowCluster = Cluster::query()->create(['sector_id' => $this->dental->id, 'service_id' => $this->implant->id, 'name' => 'İmplant ömrü',
            'intent' => 'informational', 'page_type' => 'guide', 'approved' => true]);
        ClusterQuery::query()->create(['cluster_id' => $lowCluster->id, 'query_id' => Query::query()->where('text', 'implant kaç yıl dayanır')->value('id')]);
        $lowRow = BrandClusterPage::query()->create(['brand_id' => $izmir->id, 'cluster_id' => $lowCluster->id, 'website_asset_id' => $izmirSite->id,
            'page_id' => $this->page($izmirSite, 'https://klinik.test/blog/implant-omru/')->id, 'state' => 'sufficient']);
        $izmirRow = BrandClusterPage::query()->create(['brand_id' => $izmir->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $izmirSite->id, 'page_id' => $izmirPage->id, 'state' => 'sufficient']);
        $ankaraRow = BrandClusterPage::query()->create(['brand_id' => $ankara->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $ankaraSite->id,
            'page_id' => $this->page($ankaraSite, 'https://ankara.test/implant/')->id, 'state' => 'sufficient']);

        // Search Console: last day = 3 days ago. A fact older than 90 days, another page's fact and a non-cluster query do not count.
        $last = now()->subDays(3);
        $facts = [
            ['implant fiyatları', 'https://klinik.test/implant/', 800, 40, 5.0, $last],
            ['implant ücreti', 'https://klinik.test/implant', 200, 20, 8.0, $last->copy()->subDays(10)],
            ['implant fiyatları', 'https://klinik.test/implant/', 999, 99, 1.0, $last->copy()->subDays(95)],
            ['implant fiyatları', 'https://klinik.test/blog/', 500, 5, 2.0, $last],
            ['implant sonrası ağrı', 'https://klinik.test/implant/', 300, 30, 3.0, $last],
            ['implant kaç yıl dayanır', 'https://klinik.test/blog/implant-omru/', 40, 4, 2.0, $last],
        ];
        foreach ($facts as $i => [$query, $page, $impressions, $clicks, $position, $day]) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:klinik.test',
                'reporting_date' => $day->toDateString(), 'search_type' => 'web', 'query' => $query, 'page' => $page, 'clicks' => $clicks, 'impressions' => $impressions,
                'metadata' => json_encode(['provider_average_position' => $position]), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', (string) $i), 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->artisan('moxdop:clusters:score-pages')->assertSuccessful();

        $score = DB::table('cluster_page_scores')->where('brand_cluster_page_id', $izmirRow->id)->first();
        // 1000 impressions, 60 clicks, position (800×5 + 200×8) ÷ 1000 = 5.6, 2 of 3 real queries covered.
        $this->assertSame(['scored', 1000, 60, 5.6, 2, 3], [$score->state, (int) $score->impressions, (int) $score->clicks, (float) $score->position, (int) $score->covered_queries, (int) $score->cluster_queries]);
        $this->assertSame(ClusterPageScorer::score(['position' => 5.6, 'coverage' => 2 / 3, 'ctr' => 0.06]), (int) $score->score);
        $this->assertSame('no_gsc', DB::table('cluster_page_scores')->where('brand_cluster_page_id', $ankaraRow->id)->value('state'));

        // Fewer than 100 impressions on the cluster's queries → "veri az", facts kept.
        $low = DB::table('cluster_page_scores')->where('brand_cluster_page_id', $lowRow->id)->first();
        $this->assertSame(['low_data', null, 40], [$low->state, $low->score, (int) $low->impressions]);

        // The next night keeps the last score for the trend.
        $this->artisan('moxdop:clusters:score-pages', ['--brand' => $izmir->id])->assertSuccessful();
        $this->assertSame((int) $score->score, (int) DB::table('cluster_page_scores')->where('brand_cluster_page_id', $izmirRow->id)->value('previous_score'));

        $this->artisan('moxdop:clusters:score-pages')->assertSuccessful();
        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->set('service', (string) $this->implant->id)->call('openCluster', $cluster->id)
            ->assertSee('Bu kümeye atanmış sayfalar (tüm markalar)')->assertSee('Klinik İzmir')->assertSee('Klinik Ankara')
            ->assertSee('GSC bağlı değil')->assertSee('/implant/')->assertSee('5,6')
            ->call('openCluster', $lowCluster->id)->assertSee('veri az')->assertSee('/blog/implant-omru/');
    }

    /** @return array{0: Brand, 1: DigitalAsset, 2: ?CoreExternalResource} */
    private function brand(string $name, string $domain, bool $withGsc): array
    {
        $brand = Brand::factory()->create(['name' => $name, 'customer_id' => Customer::factory()->create()->id, 'sector_id' => $this->dental->id]);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => $domain, 'primary_url' => 'https://'.$domain.'/']);
        $gsc = null;
        if ($withGsc) {
            $gsc = CoreExternalResource::factory()->searchConsole()->create();
            CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        }

        return [$brand, $site, $gsc];
    }

    private function page(DigitalAsset $site, string $url): Page
    {
        return Page::query()->create(['website_asset_id' => $site->id, 'url' => $url, 'url_hash' => hash('sha256', $url),
            'path' => (string) parse_url($url, PHP_URL_PATH), 'category' => 'hizmet']);
    }
}
