<?php

namespace Tests\Feature\Work;

use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\BrandClusterPage;
use App\Models\Suggestion;
use App\Services\Site\SiteOperations;
use App\Services\Work\ContentCoverage;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * İçerik başlıkları (yakup, 2026-10-06): the Work page shows per brand how its clusters are answered and why no title
 * waits; every Monday the sites with missing clusters and a low title stock get new titles by themselves.
 */
final class ContentCoverageTest extends SiteTestCase
{
    private function rowOf(string $state, string $name): void
    {
        $cluster = $this->cluster($this->implant, $name, [$name]);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => $state, 'language' => 'tr']);
    }

    private function title(string $title, array $action = [], string $status = Suggestion::OPEN): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($title), 'material_hash' => md5($title),
            'title' => $title, 'reason' => 'Kümenin sayfası yok.', 'priority' => 1, 'evidence' => [], 'action_type' => 'content',
            'action' => ['site_id' => $this->site->id, 'kind' => 'new'] + $action, 'status' => $status, 'target_type' => 'site', 'target_id' => $this->site->id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_the_brand_table_counts_clusters_and_titles_and_says_why_nothing_waits(): void
    {
        $coverage = app(ContentCoverage::class);
        $row = $coverage->rows()[0];
        $this->assertSame(0, $row['clusters']);
        $this->assertStringContainsString('eşleştirilmedi', (string) $row['reason']);

        $this->rowOf('no_page', 'İmplant fiyatları');
        $this->rowOf('thin_coverage', 'Tek diş implant');
        $this->rowOf('weak_performance', 'İmplant ağrısı');
        $this->rowOf('sufficient', 'Ankara implant');
        $this->title('İmplant fiyatları neye göre değişir');
        $this->title('İmplant sonrası', ['article' => ['title' => 'İmplant sonrası']], Suggestion::APPROVED);
        $this->title('Gönderilmiş', ['article_write_id' => 9], Suggestion::APPROVED);

        $row = $coverage->rows()[0];
        $this->assertSame([4, 2, 1, 1, 1, 1, 1, null], [$row['clusters'], $row['missing'], $row['weak'], $row['ok'], $row['waiting'], $row['reading'], $row['sent'], $row['reason']]);

        Livewire::test(WorkPage::class)->assertSeeHtml('data-coverage-site="'.$this->site->id.'"')->assertSee('Panorama Ankara')->assertSee('Başlık üret');
    }

    public function test_monday_run_tops_up_only_sites_with_missing_clusters_and_a_low_title_stock(): void
    {
        Queue::fake();
        $this->artisan('moxdop:content:weekly-titles')->assertSuccessful();
        Queue::assertNothingPushed(); // no missing cluster: nothing to write

        $this->rowOf('no_page', 'İmplant fiyatları');
        $this->artisan('moxdop:content:weekly-titles')->assertSuccessful();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->siteId === $this->site->id && $job->operation === SiteOperations::WEEKLY_CONTENT);

        // The stock is full (capacity 4 titles waiting): the run leaves the site alone.
        Queue::fake();
        foreach (['Bir', 'İki', 'Üç', 'Dört'] as $title) {
            $this->title($title);
        }
        $this->artisan('moxdop:content:weekly-titles')->assertSuccessful();
        Queue::assertNothingPushed();
        Livewire::test(WorkPage::class)->assertDontSeeHtml('data-make-titles');
    }

    public function test_make_titles_now_queues_the_site_run(): void
    {
        Queue::fake();
        Livewire::test(WorkPage::class)->call('makeTitles', $this->site->id)->assertSee('başlık gereken küme yok');
        Queue::assertNothingPushed();

        $this->rowOf('no_page', 'İmplant fiyatları');
        Livewire::test(WorkPage::class)->call('makeTitles', $this->site->id)->assertSee('başlıklar hazırlanıyor');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WEEKLY_CONTENT);
    }
}
