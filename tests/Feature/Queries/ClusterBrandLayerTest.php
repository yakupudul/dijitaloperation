<?php

namespace Tests\Feature\Queries;

use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Models\BrandClusterPage;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\Query;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\QueryPipeline;
use App\Services\Site\ClusterPageMapper;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * v2 düzeltmeleri (Faz 3): brand layer of brand_queries (target area / language / URL), cluster fields + version,
 * manual query add / remove, shared vs brand-only cluster edits.
 */
final class ClusterBrandLayerTest extends SiteTestCase
{
    private BrandServiceArea $cankaya;

    private BrandServiceArea $etimesgut;

    protected function setUp(): void
    {
        parent::setUp();
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        app(ServiceKeywordService::class)->replace($this->zirkonyum, 'zirkonyum');
        $this->brand->forceFill(['languages' => ['tr']])->save();
        $this->cankaya = BrandServiceArea::query()->where('brand_id', $this->brand->id)->sole();
        $this->etimesgut = BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'name' => 'Etimesgut', 'country_code' => 'TR', 'city_name' => 'Ankara',
            'district_name' => 'Etimesgut', 'normalized_key' => 'tr|ankara|etimesgut', 'status' => 'active', 'physical_branch' => false]);
    }

    public function test_brand_targets_per_area_for_commercial_one_row_for_informational_with_language_url_and_main_service_first(): void
    {
        $crown = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        $center = $this->cluster($this->implant, 'İmplant merkezi', ['implant merkezi', 'implant kliniği']);
        $pain = $this->cluster($this->implant, 'İmplant sonrası ağrı', ['implant sonrası ağrı'], [], 'informational');
        $draft = $this->cluster($this->implant, 'Taslak', ['implant taslak']);
        $draft->forceFill(['approved' => false])->save();
        $page = $this->page('/implant/', 'Ankara İmplant Merkezi', ['category' => 'hizmet']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $center->id, 'website_asset_id' => $this->site->id,
            'page_id' => $page->id, 'language' => 'tr', 'state' => 'sufficient']);

        app(QueryPipeline::class)->run();

        $rows = DB::table('brand_queries')->where('brand_id', $this->brand->id)->orderBy('id')->get();
        $main = fn (Cluster $c): int => (int) $c->main_query_id;
        $this->assertSame([
            [$main($center), $this->cankaya->id], [$main($center), $this->etimesgut->id], [$main($pain), null],
            [$main($crown), $this->cankaya->id], [$main($crown), $this->etimesgut->id],
        ], $rows->map(fn (object $r): array => [(int) $r->query_id, $r->target_area_id !== null ? (int) $r->target_area_id : null])->all(),
            'main service (implant) first; per area only for commercial; unapproved cluster ignored');
        $this->assertSame(['tr'], $rows->pluck('language')->unique()->values()->all());
        $this->assertSame('https://panorama.com.tr/implant/', $rows->firstWhere('query_id', $main($center))->url);
        $this->assertNull($rows->firstWhere('query_id', $main($pain))->url);

        // Brand language unset → the site's primary page language; idempotent.
        $this->brand->forceFill(['languages' => []])->save();
        $this->page('/en/implant/', 'Implant', ['language' => 'en']);
        $this->page('/iletisim/', 'İletişim');
        app(QueryPipeline::class)->run();
        $this->assertSame(5, DB::table('brand_queries')->where('brand_id', $this->brand->id)->count());
        $this->assertSame(['tr'], DB::table('brand_queries')->distinct()->pluck('language')->all());

        // Excluded for the brand → no target rows; the shared cluster is untouched.
        app(ClusterEditor::class)->brandEdit($crown, $this->brand, ['excluded' => true]);
        $this->assertFalse(DB::table('brand_queries')->where('query_id', $main($crown))->exists());
        $this->assertSame(1, $crown->refresh()->version);
    }

    public function test_cluster_drawer_edits_every_field_and_raises_the_version_with_shared_confirmation(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant fiyatı', ['implant fiyatı', 'implant ücreti', 'implant fiyatları 2026']);
        $ids = ClusterQuery::query()->where('cluster_id', $cluster->id)->orderBy('query_id')->pluck('query_id')->all();

        $page = Livewire::test(QueriesPage::class)->set('tab', 'clusters')->set('service', (string) $this->implant->id)
            ->call('openCluster', $cluster->id)
            ->assertSee('Ortak kütüphaneyi düzenle')->assertSee('Panorama Ankara')
            ->set('clusterForm.intent', 'informational')->set('clusterForm.page_type', 'guide')
            ->set('clusterForm.user_need', 'Tedavinin fiyatını öğrenmek')
            ->set('clusterForm.main_query_id', (string) $ids[1])->set('clusterForm.representative_query_ids', [(string) $ids[0], (string) $ids[2]])
            ->set('clusterForm.subtopics', "Fiyatı etkileyenler\n\nFiyatı etkileyenler\nTaksit")
            ->set('clusterForm.exclusions', 'İmplant sonrası ağrı')
            ->call('saveCluster')->assertHasErrors('confirmShared');
        $this->assertSame('commercial', $cluster->refresh()->intent, 'not saved without confirmation');
        $this->assertSame(1, $cluster->version);

        $page->set('confirmShared', true)->call('saveCluster')->assertHasNoErrors();
        $cluster->refresh();
        $this->assertSame(['informational', 'guide', 'Tedavinin fiyatını öğrenmek', $ids[1]], [$cluster->intent, $cluster->page_type, $cluster->user_need, (int) $cluster->main_query_id]);
        $this->assertSame([$ids[0], $ids[2]], $cluster->representative_query_ids);
        $this->assertSame(['Fiyatı etkileyenler', 'Taksit'], $cluster->subtopics);
        $this->assertSame(['İmplant sonrası ağrı'], $cluster->exclusions);
        $this->assertSame(2, $cluster->version);
        $this->assertTrue($cluster->locked);
        $page->assertSee('temsil')->assertSee('sürüm 2');

        $page->set('clusterForm.main_query_id', '999999')->call('saveCluster')->assertHasErrors('clusterForm.main_query_id');
        $page->call('approveCluster');
        $this->assertSame(3, $cluster->refresh()->version);
    }

    public function test_add_and_remove_queries_manually_and_suggested_flag_clears_with_real_data(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant fiyatı', ['implant fiyatı']);
        $other = $this->cluster($this->implant, 'İmplant kliniği', ['implant kliniği', 'implant merkezi']);
        $loose = Query::query()->create(['text' => 'implant ücreti', 'text_hash' => hash('sha256', 'implant ücreti'), 'sector_id' => $this->dental->id]);
        DB::table('query_sources')->insert(['external_resource_id' => $this->gsc->id, 'source' => 'gsc', 'raw_query' => 'implant ücreti', 'month' => '2026-09-01',
            'impressions' => 5, 'clicks' => 0, 'query_id' => $loose->id, 'created_at' => now(), 'updated_at' => now()]);

        $page = Livewire::test(QueriesPage::class)->set('tab', 'clusters')->set('service', (string) $this->implant->id)
            ->call('openCluster', $cluster->id)->set('confirmShared', true)
            ->set('addQueryText', 'İmplant Ücreti')->call('addQueryToCluster')->assertHasNoErrors()
            ->set('addQueryText', 'implant merkezi')->call('addQueryToCluster')
            ->set('addQueryText', 'implant taksit')->call('addQueryToCluster')
            ->set('addQueryText', 'implant taksit')->call('addQueryToCluster')->assertHasErrors('addQueryText');

        $members = ClusterQuery::query()->where('cluster_id', $cluster->id)->with('searchQuery')->get()->keyBy(fn ($l) => $l->searchQuery->text);
        $this->assertSame(['implant fiyatı', 'implant merkezi', 'implant taksit', 'implant ücreti'], $members->keys()->sort()->values()->all());
        $this->assertSame($this->implant->id, $loose->refresh()->service_id, 'unclustered query takes the service (manual)');
        $this->assertTrue($loose->locked);
        $this->assertSame(1, $other->clusterQueries()->count(), 'moved from the other cluster');
        $this->assertTrue($members['implant taksit']->is_suggested, 'new text = önerilen until real data');

        app(QueryPipeline::class)->run();
        $taksit = Query::query()->where('text', 'implant taksit')->sole();
        $this->assertTrue($taksit->is_suggested, 'suggested query survives the pipeline');

        DB::table('query_sources')->insert(['external_resource_id' => $this->gsc->id, 'source' => 'gsc', 'raw_query' => 'implant taksit', 'month' => '2026-09-01',
            'impressions' => 12, 'clicks' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(QueryPipeline::class)->run();
        $this->assertFalse($taksit->refresh()->is_suggested);
        $this->assertFalse(ClusterQuery::query()->where('query_id', $taksit->id)->value('is_suggested'), 'membership flag cleared too');

        $suggested = Query::query()->create(['text' => 'implant kampanya', 'text_hash' => hash('sha256', 'implant kampanya'), 'service_id' => $this->implant->id, 'is_suggested' => true]);
        ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $suggested->id, 'is_suggested' => true]);
        $page->set('selectedClusterQueries', [$loose->id, $suggested->id])->call('removeClusterQueries')->assertHasNoErrors();
        $this->assertFalse(ClusterQuery::query()->where('query_id', $loose->id)->exists());
        $this->assertTrue(Query::query()->whereKey($loose->id)->exists(), 'real query stays (kümesiz)');
        $this->assertFalse(Query::query()->whereKey($suggested->id)->exists(), 'suggested query goes');
    }

    public function test_brand_only_edit_never_changes_the_shared_cluster_and_both_screens_show_the_same_record(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant merkezi', ['implant merkezi']);
        $page = $this->page('/implant/', 'Ankara İmplant Merkezi', ['category' => 'hizmet']);

        Livewire::test(QueriesPage::class)->set('tab', 'clusters')->set('service', (string) $this->implant->id)
            ->call('openCluster', $cluster->id)->assertSee('Bu markaya özel düzenle')
            ->set('brandId', (string) $this->brand->id)->assertSee('/implant/')
            ->set('brandTarget', 'çankaya implant merkezi')->set('brandPage', (string) $page->id)->call('saveBrandCluster')->assertHasNoErrors()
            ->assertSee('çankaya implant merkezi');

        $row = BrandClusterPage::query()->where('brand_id', $this->brand->id)->where('cluster_id', $cluster->id)->sole();
        $this->assertSame([$page->id, 'çankaya implant merkezi', 'çankaya implant merkezi', true, 'manual'], [$row->page_id, $row->target_query_override, $row->target_query, $row->locked, $row->decided_by]);
        $cluster->refresh();
        $this->assertSame([1, false, 'İmplant merkezi'], [$cluster->version, $cluster->locked, $cluster->name], 'shared cluster untouched');
        $this->assertSame('https://panorama.com.tr/implant/', DB::table('brand_queries')->where('query_id', $cluster->main_query_id)->value('url'));

        // Website screen edits the same record through ClusterEditor; Sorgular shows it.
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])
            ->set("edit.{$row->id}.target", '')->set("edit.{$row->id}.excluded", true)->call('saveMain', $row->id);
        $row->refresh();
        $this->assertNull($row->target_query_override);
        $this->assertSame('ankara implant merkezi', $row->target_query, 'automatic target query again');
        $this->assertTrue($row->excluded);
        $this->assertFalse(DB::table('brand_queries')->where('brand_id', $this->brand->id)->exists(), 'excluded → no brand target');
        Livewire::test(QueriesPage::class)->set('tab', 'clusters')->set('service', (string) $this->implant->id)
            ->call('openCluster', $cluster->id)->assertSee('hariç');

        // The mapper keeps the excluded row as it is.
        app(ClusterPageMapper::class)->refresh($this->site);
        $this->assertTrue($row->refresh()->excluded);
        $this->assertSame(1, BrandClusterPage::query()->count());
    }
}
