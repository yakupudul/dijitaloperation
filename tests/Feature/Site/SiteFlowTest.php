<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Models\BrandClusterPage;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\ExternalWriteAction;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandGaps;
use App\Services\Site\ClusterAudit;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\SiteAreas;
use App\Services\Site\SiteFlow;
use App\Services\Site\SiteOperations;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Site akışı: WordPress-paired sites run categorize → service ↔ page → cluster ↔ page by themselves; a cluster that
 * matches several pages shows them with a recommendation (301 ile birleştir / ayrıştır); an unmatched cluster gets its
 * content idea; service areas come from the site's own pages.
 */
final class SiteFlowTest extends SiteTestCase
{
    private function pair(): CoreConnection
    {
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.4.1']]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => str_repeat('s', 43)]]);

        return $connection;
    }

    public function test_the_flow_waits_for_wordpress_then_runs_the_next_due_step_once(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page']);

        $this->assertSame('waiting:wordpress', SiteFlow::advance($this->site));
        Queue::assertNothingPushed();
        config(['moxdop-ai-pricing.automatic_areas' => ['queries']]);
        $this->pair();
        $this->assertSame('waiting:manual', SiteFlow::advance($this->site), 'only Sorgular runs by itself: the site flow waits for a click');
        Queue::assertNothingPushed();
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->call('advanceFlow')->assertSee('Sayfa sınıflandırma ve hizmet');
        Queue::assertPushed(RunSiteOperationJob::class, 1);
        config(['moxdop-ai-pricing.automatic_areas' => ['*']]);
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::SETUP, ['status' => 'ready'], ['unattended' => true]);
        Cache::forget('site-setup:unmatched:'.$this->site->id);
        Queue::fake();

        $this->assertSame('setup', SiteFlow::advance($this->site), 'service pages but no service ↔ page match: the setup first');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::SETUP);
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::SETUP, ['status' => 'ready'], ['unattended' => true]);
        $this->assertSame('audit', SiteFlow::advance($this->site), 'rows never read: Eşleştir is due');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT);
        $this->assertSame('running', SiteFlow::advance($this->site), 'never twice while one runs');

        // Done and nothing changed: no new AI run. A changed page content makes it due again.
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::CLUSTER_AUDIT, ['status' => 'ready']);
        BrandClusterPage::query()->update(['audited_at' => now()]);
        SiteFlow::audited($this->site);
        $this->assertSame('ready', SiteFlow::advance($this->site));
        // A changed page text alone re-runs Eşleştir at most once a week (pages that change on every crawl cost nothing nightly).
        Page::query()->where('path', '/implant/')->update(['content_hash' => hash('sha256', 'yeni metin')]);
        $this->assertFalse(SiteFlow::auditDue($this->site));
        $this->travel(SiteFlow::CONTENT_RERUN_DAYS + 1)->days();
        $this->assertTrue(SiteFlow::auditDue($this->site));
        $this->travelBack();
        // A new page or a changed category counts at once.
        SiteFlow::audited($this->site);
        $this->page('/zirkonyum/', 'Zirkonyum', ['category' => 'hizmet']);
        $this->assertTrue(SiteFlow::auditDue($this->site));

        $steps = collect(SiteFlow::steps($this->site))->keyBy('key');
        $this->assertTrue($steps['wordpress']['done']);
        $this->assertSame('1 / 1 küme okundu', explode(' · ', $steps['cluster_match']['detail'])[0]);
    }

    public function test_a_cluster_matching_several_pages_lists_them_with_a_recommendation_and_the_operator_decides(): void
    {
        $this->enableAi();
        $this->pair();
        $main = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'content_text' => 'İmplant tedavisi anlatılır.']);
        $copy = $this->page('/implant-tedavisi-nedir/', 'İmplant tedavisi nedir', ['category' => 'blog', 'content_text' => 'İmplant tedavisi nedir.']);
        $other = $this->page('/zirkonyum/', 'Zirkonyum Kaplama ve İmplant', ['category' => 'hizmet', 'content_text' => 'Zirkonyum kaplama ve implant.']);
        $treatment = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nedir']);
        $aftercare = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası ne yenir'], ['İlk gün', 'Beslenme'], 'informational');
        $aftercare->forceFill(['user_need' => 'İmplanttan sonra neye dikkat edileceğini öğrenmek', 'page_type' => 'guide'])->save();
        $crown = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama implant']);
        ClusterAiQueriesAgent::fake(fn (): array => ['clusters' => []]);
        ClusterMatchAgent::fake(fn (): array => ['clusters' => [
            ['cluster_id' => $treatment->id, 'page_id' => $main->id, 'also_page_ids' => [$copy->id, $other->id, $main->id, 424242], 'coverage' => 'full', 'reason' => 'Tedavi sayfası.'],
            ['cluster_id' => $aftercare->id, 'page_id' => null, 'also_page_ids' => [], 'coverage' => 'none', 'reason' => 'Bakım sayfası yok.'],
            ['cluster_id' => $crown->id, 'page_id' => $other->id, 'also_page_ids' => [], 'coverage' => 'full', 'reason' => 'Kaplama sayfası.'],
        ]]);
        ClusterGapsAgent::fake(fn (): array => ['clusters' => []]);

        app(ClusterAudit::class)->run($this->site);

        $row = BrandClusterPage::query()->where('cluster_id', $treatment->id)->sole();
        $this->assertEqualsCanonicalizing([$copy->id, $other->id], $row->overlap_page_ids, 'the main page and unknown ids are never an overlap');
        $suggestions = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->get()->keyBy('page_id');
        $this->assertCount(2, $suggestions);
        $this->assertSame(ClusterOverlaps::REDIRECT, data_get($suggestions[$copy->id]->action, 'recommendation'), 'little traffic, no other cluster: merge');
        $this->assertSame(ClusterOverlaps::DIFFERENTIATE, data_get($suggestions[$other->id]->action, 'recommendation'), 'target of another cluster: never redirected');
        $this->assertFalse(SiteFlow::auditDue($this->site), 'the run is remembered');

        Queue::fake();
        $page = Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])
            ->assertSeeHtml('data-site-flow')->assertSeeHtml('data-overlaps')->assertSee('Bu kümeyle eşleşen diğer sayfalar (2)')
            ->assertSeeHtml('data-merge-overlap')->assertSeeHtml('data-content-idea')
            ->assertSee('yeni rehber sayfası · «İmplant sonrası bakım»')->assertSee('İmplanttan sonra neye dikkat edileceğini öğrenmek')->assertSee('Bölümler: İlk gün · Beslenme');

        $page->call('mergeOverlap', $suggestions[$other->id]->id)->assertHasErrors('write');
        $page->call('keepOverlap', $suggestions[$other->id]->id)->assertSee('İki sayfa ayrı kalıyor');
        $this->assertSame(Suggestion::DISMISSED, $suggestions[$other->id]->fresh()->status);

        $page->call('mergeOverlap', $suggestions[$copy->id]->id)->assertHasErrors('write');
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'connector 1.4.1 cannot write to the SEO plugin: nothing is sent');
        CoreConnection::query()->update(['config->plugin_version' => '1.10.0']);

        $page->call('mergeOverlap', $suggestions[$copy->id]->id)->assertSee('301 siteye gönderildi');
        $write = ExternalWriteAction::query()->sole();
        $this->assertSame([['type' => 'merge_redirect', 'object_id' => 0, 'from' => '/implant-tedavisi-nedir/', 'value' => 'https://panorama.com.tr/implant/', 'reference' => 'suggestion-'.$suggestions[$copy->id]->id.'-merge']],
            $write->request_payload['changes']);
        $this->assertSame(Suggestion::APPROVED, $suggestions[$copy->id]->fresh()->status, 'waits for the site (the write is queued)');
        $this->assertTrue(ClusterOverlaps::mergePending($suggestions[$copy->id]->fresh()));

        // The overlap goes away on the next run: an open suggestion closes itself; a decided one stays decided.
        ClusterMatchAgent::fake(fn (): array => ['clusters' => [
            ['cluster_id' => $treatment->id, 'page_id' => $main->id, 'also_page_ids' => [], 'coverage' => 'full', 'reason' => 'Tedavi sayfası.'],
        ]]);
        app(ClusterAudit::class)->run($this->site);
        $this->assertSame([], Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->actionable()->pluck('id')->all());
    }

    /** Two services, two pages; every AI call takes longer than a run may last. @return array{match: int, gaps: int} */
    private function slowAi(): object
    {
        $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'content_text' => 'İmplant tedavisi anlatılır.']);
        $this->page('/zirkonyum/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'content_text' => 'Zirkonyum kaplama anlatılır.']);
        $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        Cluster::query()->update(['ai_queries' => '[]']);
        $calls = (object) ['match' => 0, 'gaps' => 0];
        ClusterMatchAgent::fake(function (string $prompt) use ($calls): array {
            $calls->match++;
            $this->travel(ClusterAudit::RUN_SECONDS + 1)->seconds();
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['clusters' => array_map(fn (array $c): array => ['cluster_id' => $c['cluster_id'], 'page_id' => $data['pages'][0]['id'],
                'also_page_ids' => [], 'coverage' => 'full', 'reason' => 'Sayfa konuyu işliyor.'], $data['clusters'])];
        });
        ClusterGapsAgent::fake(function () use ($calls): array {
            $calls->gaps++;
            $this->travel(ClusterAudit::RUN_SECONDS + 1)->seconds();

            return ['clusters' => []];
        });

        return $calls;
    }

    public function test_a_large_eslestir_runs_in_parts_and_never_pays_for_a_step_twice(): void
    {
        $this->enableAi();
        $calls = $this->slowAi();
        $audit = app(ClusterAudit::class);

        $parts = 0;
        do {
            $result = $audit->run($this->site, continueOnly: $parts > 0);
            $parts++;
        } while ($result['status'] === 'partial' && $parts < 10);

        $this->assertSame(['ready', 4, 2, 2], [$result['status'], $parts, $calls->match, $calls->gaps], 'match A | match B | gaps 1 | gaps 2: each part continues, nothing twice');
        $this->assertFalse(ClusterAudit::passOpen($this->site));
        $this->assertFalse(SiteFlow::auditDue($this->site));
        $this->assertSame('ready', $audit->run($this->site, continueOnly: true)['status'], 'a late follow-up part finds no open pass');
        $this->assertSame([2, 2], [$calls->match, $calls->gaps]);
    }

    public function test_a_part_out_of_time_queues_the_next_and_a_failed_eslestir_is_not_retried_in_a_loop(): void
    {
        $this->enableAi();
        $this->pair();
        $this->slowAi();
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => Page::query()->where('path', '/implant/')->value('id'), 'source' => 'rule', 'locked' => false]);
        Queue::fake();
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::SETUP, ['status' => 'ready'], ['unattended' => true]);

        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::CLUSTER_AUDIT))->handle(app(SiteOperations::class));
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT && $job->params === ['part' => 2]);
        $this->assertTrue(SiteFlow::auditRunning((int) $this->site->id));
        $this->assertSame('running', SiteFlow::advance($this->site), 'no second Eşleştir while parts run');
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->call('matchAll')->assertSee('Eşleştirme zaten çalışıyor');
        Queue::assertPushed(RunSiteOperationJob::class, 1);

        // The worker killed a part for its timeout: the flow waits instead of starting it again every few minutes.
        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::CLUSTER_AUDIT, ['part' => 2]))->failed(new TimeoutExceededException('timed out'));
        $this->assertSame('timeout', SiteOperations::status((int) $this->site->id, SiteOperations::CLUSTER_AUDIT)['status']);
        $this->assertSame('paused', SiteFlow::advance($this->site));
        $this->assertSame('paused', SiteFlow::advance($this->site));
        Queue::assertPushed(RunSiteOperationJob::class, 1);
        $this->travel(SiteFlow::FAILURE_PAUSE_HOURS + 1)->hours();
        $this->assertSame('audit', SiteFlow::advance($this->site), 'later it continues the open pass');
        Queue::assertPushed(RunSiteOperationJob::class, 2);
    }

    public function test_service_areas_come_from_the_sites_own_pages_and_are_added_on_approval(): void
    {
        BrandServiceArea::query()->where('brand_id', $this->brand->id)->delete();
        $this->page('/bornova-implant/', 'Bornova İmplant Kliniği', ['category' => 'lokasyon']);
        $this->page('/bornova-dis-klinigi/', 'Bornova Diş Kliniği', ['category' => 'lokasyon']);
        $this->page('/karsiyaka-implant/', 'Karşıyaka İmplant', ['category' => 'lokasyon']);
        $this->page('/karsiyaka-zirkonyum/', 'Karşıyaka Zirkonyum', ['category' => 'hizmet']);
        $this->page('/izmir/', 'İzmir Diş Kliniği', ['category' => 'kurumsal']);
        $this->page('/ankara-tek-sayfa/', 'Ankara implant', ['category' => 'lokasyon']);

        $this->assertSame(['Bornova / İzmir', 'Karşıyaka / İzmir'], array_column(SiteAreas::detect($this->site), 'label'),
            'districts of a named province; a name on one page only is not proposed; the province is not listed besides its districts');

        app(BrandGaps::class)->sync($this->brand);
        $gap = Suggestion::query()->where('decision_key', BrandGaps::DECISION)->where('title', 'Sitede 2 hizmet bölgesi bulundu')->sole();
        $this->assertSame(0, BrandServiceArea::query()->where('brand_id', $this->brand->id)->count(), 'nothing before approval');
        $this->assertSame('2 hizmet bölgesi eklendi.', app(BrandGaps::class)->apply($gap, $this->admin));
        $this->assertEqualsCanonicalizing(['Bornova', 'Karşıyaka'], BrandServiceArea::query()->where('brand_id', $this->brand->id)->pluck('district_name')->all());
    }
}
