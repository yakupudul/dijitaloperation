<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\GenerateContentIdeasJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Website\V2\ClustersBoardTab;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\ContentIdea;
use App\Models\Query;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Site\SiteOperations;
use Illuminate\Support\Facades\Queue;
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
        $html = Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id, 'range' => ['days' => 28]])
            ->assertSee('ANA HİZMET')->assertSee('Hizmete bağlı olmayan kümeler')
            ->assertSeeInOrder(['Diş İmplantı', 'İmplant tedavisi', 'İmplant fiyatları', 'İmplant ağrısı', 'Tek diş implant', 'Zirkonyum Kaplama', 'Zirkonyum kaplama', 'Hizmete bağlı olmayan kümeler', 'Şeffaf plak'])
            ->assertSee('Ana küme')->assertSee('Alt küme')->assertSee('Tüm alt kümeler (1 daha)')
            ->assertSee('Olmazsa olmaz')->assertSee('İmplant sonrası beslenme')->assertSee('Sitede: ')->assertSee('Sitede yok')
            ->assertSee('Sektör talebi')->assertSee('Bizim gösterim')->assertSee('+ Yeni fikir üret')
            ->assertSeeHtml('data-cluster-card="'.$this->clusters['İmplant tedavisi']->id.'" data-matched="1"')
            ->assertSeeHtml('data-cluster-card="'.$this->clusters['İmplant fiyatları']->id.'" data-matched="0"')
            ->html();
        $this->assertStringContainsString('implant nasıl yapılır', $html, 'card query tab lists the cluster queries');

        Livewire::test(ClustersBoardTab::class, ['assetId' => $this->site->id])
            ->set('match', 'eslendi')->assertSee('İmplant tedavisi')->assertDontSee('İmplant fiyatları')
            ->set('match', '')->set('service', (string) $this->zirkonyum->id)->assertSee('Zirkonyum kaplama')->assertDontSee('İmplant ağrısı')
            ->set('service', '0')->assertSee('Şeffaf plak')->assertDontSee('Zirkonyum kaplama')
            ->set('service', '')->set('type', 'guide')->assertSee('İmplant ağrısı')->assertDontSee('Zirkonyum kaplama')
            ->set('type', '')->set('search', 'nasıl yapılır')->assertSee('İmplant tedavisi')->assertDontSee('Tek diş implant')
            ->set('search', 'yok-böyle')->assertSee('Bu filtrede küme yok.');
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
