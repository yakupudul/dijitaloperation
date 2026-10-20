<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\GenerateContentIdeasJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Website\V2\ClustersBoardTab;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\ContentIdea;
use App\Models\Page;
use App\Models\Query;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Site\SiteOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** Web sitesi › Kümeler: cards grouped by service, ana küme first, card ideas / queries, filters and actions. */
final class ClustersBoardTabTest extends SiteTestCase
{
    /** @var array<string, Cluster> */
    private array $clusters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $page = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 41]);
        $other = app(ServiceCatalogService::class)->resolveOrCreate('Ortodonti', 'dental', actor: $this->admin)['service'];
        foreach ([
            ['İmplant fiyatları', $this->implant, ['implant fiyatları'], 'service', 900],
            ['İmplant tedavisi', $this->implant, ['implant tedavisi', 'implant nasıl yapılır'], 'service', 2000],
            ['İmplant ağrısı', $this->implant, ['implant ağrısı'], 'guide', 300],
            ['Tek diş implant', $this->implant, ['tek diş implant'], 'guide', 100],
            ['Zirkonyum kaplama', $this->zirkonyum, ['zirkonyum kaplama'], 'service', 500],
            ['Şeffaf plak', $other, ['şeffaf plak'], 'service', 50],
        ] as [$name, $service, $queries, $type, $demand]) {
            $cluster = $this->cluster($service, $name, $queries);
            $cluster->forceFill(['page_type' => $type])->save();
            Query::query()->whereIn('text', $queries)->update(['impressions' => $demand]);
            BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
                'page_id' => $name === 'İmplant tedavisi' ? $page->id : null, 'state' => $name === 'İmplant tedavisi' ? 'sufficient' : 'no_page', 'language' => 'tr']);
            $this->clusters[$name] = $cluster;
        }
        ContentIdea::query()->create(['cluster_id' => $this->clusters['İmplant tedavisi']->id, 'title' => 'İmplant sonrası beslenme', 'title_key' => 'implant sonrasi beslenme',
            'type' => 'guide', 'angle' => 'İlk hafta.', 'target_queries' => [], 'outline' => []]);
        $this->fact('implant tedavisi', '/implant/', 400, 12, 6.0);
    }

    public function test_cards_are_grouped_by_service_with_the_main_cluster_first(): void
    {
        $this->actingAs($this->admin);
        // Only the first service renders its cards (three of them); the others open with "Göster", the rest of a service
        // with "Tüm alt kümeler" (a site can have hundreds of clusters).
        // Sade panel (2026-10-20): the main-service mark is plain gray text "Ana hizmet"; the all-caps orange chip
        // ("ANA HİZMET") was decoration.
        $board = Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id, 'range' => ['days' => 28]])
            ->assertSee('Ana hizmet</span>', false)->assertSee('Hizmete bağlı olmayan kümeler')
            ->assertSeeInOrder(['Diş İmplantı', 'İmplant tedavisi', 'İmplant fiyatları', 'İmplant ağrısı', 'Zirkonyum Kaplama', 'Hizmete bağlı olmayan kümeler'])
            ->assertDontSee('Tek diş implant')->assertDontSee('Zirkonyum kaplama')->assertDontSee('Şeffaf plak')
            ->assertSeeHtml('data-open-section="'.$this->zirkonyum->id.'"')->assertSeeHtml('data-open-section="0"');
        $html = $board->call('toggleAll', $this->implant->id)->call('openSection', $this->zirkonyum->id)->call('openSection', 0)
            ->assertSee('Ana küme')->assertSee('Alt küme')->assertSee('Daha az göster')
            ->assertSee('Olmazsa olmaz')->assertSee('İmplant sonrası beslenme')->assertSee('Sitede: ')->assertSee('Sitede yok')
            ->assertSee('Sektör talebi')->assertSee('Bizim gösterim')->assertSee('+ Yeni fikir üret')
            ->assertSeeHtml('data-cluster-card="'.$this->clusters['İmplant tedavisi']->id.'" data-matched="1"')
            ->assertSeeHtml('data-cluster-card="'.$this->clusters['İmplant fiyatları']->id.'" data-matched="0"')
            ->html();
        $this->assertStringContainsString('implant nasıl yapılır', $html, 'card query tab lists the cluster queries');
        $at = 0;
        foreach (['Diş İmplantı', 'İmplant tedavisi', 'İmplant fiyatları', 'İmplant ağrısı', 'Tek diş implant', 'Zirkonyum Kaplama', 'Zirkonyum kaplama', 'Hizmete bağlı olmayan kümeler', 'Şeffaf plak'] as $text) {
            $found = mb_strpos($html, $text, $at);
            $this->assertNotFalse($found, $text.' after the opened services');
            $at = $found + 1;
        }

        Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id])
            ->set('match', 'eslendi')->assertSee('İmplant tedavisi')->assertDontSee('İmplant fiyatları')
            ->set('match', '')->set('service', (string) $this->zirkonyum->id)->assertSee('Zirkonyum kaplama')->assertDontSee('İmplant ağrısı')
            ->set('service', '0')->assertSee('Şeffaf plak')->assertDontSee('Zirkonyum kaplama')
            ->set('service', '')->set('type', 'guide')->assertSee('İmplant ağrısı')->assertDontSee('Zirkonyum kaplama')
            ->set('type', '')->set('search', 'nasıl yapılır')->assertSee('İmplant tedavisi')->assertDontSee('Tek diş implant')
            ->set('search', 'yok-böyle')->assertSee('Bu filtrede küme yok.')
            ->call('clearFilters')->assertSet('search', '')->assertSee('İmplant tedavisi');
    }

    public function test_the_board_reads_a_fixed_number_of_queries_however_many_clusters_the_site_has(): void
    {
        // The statuses of the rows live in the cache; with the database store each read used to be a query.
        config(['cache.default' => 'database']);
        $this->actingAs($this->admin);
        $add = function (int $from, int $count): void {
            for ($i = $from; $i < $from + $count; $i++) {
                $cluster = $this->cluster($i % 2 ? $this->implant : $this->zirkonyum, 'Ek küme '.$i, ['ek sorgu '.$i]);
                BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page', 'language' => 'tr']);
                ContentIdea::query()->create(['cluster_id' => $cluster->id, 'title' => 'Ek fikir '.$i, 'title_key' => 'ek fikir '.$i, 'type' => 'guide', 'angle' => 'x', 'target_queries' => [], 'outline' => []]);
            }
        };
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id, 'range' => ['days' => 28]])->assertSee('Ek küme');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        // The first render of a site fills the analysis caches; the second one is what every later click costs.
        $add(0, 10);
        $queries();
        $few = $queries();
        $add(10, 40);
        $queries();

        $this->assertSame($few, $queries(), 'more clusters, ideas and cards do not add queries');
        $this->assertLessThan(60, $few);
        $this->assertEqualsCanonicalizing(array_values(array_diff(Schema::getColumnListing('pages'), ['content_text', 'content_outline', 'headings'])), Page::LIST_COLUMNS,
            'the rows read every page column but the stored text');
    }

    public function test_cards_list_each_idea_once_offer_one_create_and_fold_small_clusters(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);
        $prices = $this->clusters['İmplant fiyatları'];
        ContentIdea::query()->create(['cluster_id' => $prices->id, 'title' => 'İmplant Fiyatları!', 'title_key' => 'implant fiyatlari x', 'type' => 'guide', 'angle' => 'x', 'target_queries' => [], 'outline' => []]);
        $row = BrandClusterPage::query()->where('cluster_id', $prices->id)->sole();
        $row->forceFill(['coverage' => 'none', 'audited_at' => now()])->save();
        $tiny = $this->cluster($this->implant, 'İmplant kampanya kodu', ['implant kampanya kodu']);
        $tiny->forceFill(['page_type' => 'guide'])->save();
        Query::query()->where('text', 'implant kampanya kodu')->update(['impressions' => 3]);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $tiny->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page', 'language' => 'tr']);

        $board = Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id, 'range' => ['days' => 28]]);
        $html = $board->html();
        $card = mb_substr($html, mb_strpos($html, 'data-cluster-card="'.$prices->id.'"'));
        $card = mb_substr($card, 0, mb_strpos($card, '</article>'));
        $this->assertSame(1, mb_substr_count($card, 'data-card-idea='), 'the pool idea with the cluster\'s own title is not listed twice');
        $this->assertStringContainsString('data-produce-card="'.$prices->id.'"', $card);
        $this->assertStringContainsString('Yalnız bunu oluştur', $card);
        $board->assertSeeHtml('data-small-clusters="'.$this->implant->id.'"')->assertSee('Küçük kümeler (1)')
            ->assertDontSeeHtml('data-cluster-card="'.$tiny->id.'"');

        $board->call('produceCard', [['kind' => 'main', 'id' => $row->id]])->assertSet('message', '1 sayfa yazılıyor; hazır olunca İçerik sekmesinde inceleyip WordPress taslağı olarak gönderirsin.');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::PRODUCE);

        $this->assertSame(['A', 'b'], array_column(ClustersBoardTab::uniqueIdeas([['title' => 'A'], ['title' => 'a '], ['title' => 'b']]), 'title'));
    }

    public function test_card_actions_queue_work_only_on_click(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);
        $row = BrandClusterPage::query()->where('cluster_id', $this->clusters['İmplant fiyatları']->id)->sole();

        Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id])
            ->call('rediscover', 'main', $row->id)
            ->call('generateIdeas', $this->clusters['İmplant ağrısı']->id)
            ->call('matchAll');

        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::REDISCOVER);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT);
        Queue::assertPushed(GenerateContentIdeasJob::class, 1);
    }
}
