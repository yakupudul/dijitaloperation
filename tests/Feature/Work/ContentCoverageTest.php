<?php

namespace Tests\Feature\Work;

use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Services\Work\ContentCoverage;
use Illuminate\Support\Facades\DB;
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

    public function test_the_brand_table_counts_clusters_and_the_main_language_pool_and_says_why_nothing_waits(): void
    {
        $coverage = app(ContentCoverage::class);
        $row = $coverage->rows()[0];
        $this->assertSame(0, $row['clusters']);
        $this->assertStringContainsString('eşleştirilmedi', (string) $row['reason']);

        $this->page('/implant/', 'İmplant', ['category' => 'hizmet', 'language' => 'tr']);
        $this->page('/implant-fiyat/', 'İmplant fiyatı', ['category' => 'hizmet', 'language' => 'tr']);
        $this->page('/en/dental-implant/', 'Dental Implant', ['category' => 'hizmet', 'language' => 'en']);
        $this->rowOf('no_page', 'İmplant fiyatları');
        $this->rowOf('thin_coverage', 'Tek diş implant');
        $this->rowOf('weak_performance', 'İmplant ağrısı');
        $this->rowOf('sufficient', 'Ankara implant');
        $this->title('İmplant fiyatları neye göre değişir', ['angle' => 'decision'])->forceFill(['cluster_id' => Cluster::query()->where('name', 'İmplant fiyatları')->value('id'), 'reason' => 'Fiyat sorgusu çok, sayfa yok.'])->save();
        $this->title('Dental implant aftercare', ['language' => 'en']);
        $this->title('İmplant sonrası', ['article' => ['title' => 'İmplant sonrası']], Suggestion::APPROVED);
        $this->title('Gönderilmiş', ['article_write_id' => 9], Suggestion::APPROVED);

        $row = $coverage->rows()[0];
        $this->assertSame([4, 2, 1, 1, ['tr' => 1], ['en'], 1, 1, null], [$row['clusters'], $row['missing'], $row['weak'], $row['ok'], $row['pool'], $row['translated'], $row['reading'], $row['sent'], $row['reason']]);

        Livewire::test(WorkPage::class)->assertSeeHtml('data-coverage-site="'.$this->site->id.'"')->assertSee('TR 1/20')->assertDontSee('EN 1/20')->assertSee('EN: yazılınca çevrilir')
            ->assertSee('Fikir üret')->assertSee('Karar desteği')->assertSee('Küme: İmplant fiyatları')->assertSee('Fiyat sorgusu çok, sayfa yok.');
    }

    public function test_the_content_line_shows_where_it_waits_and_what_sent_articles_brought(): void
    {
        $this->rowOf('no_page', 'İmplant fiyatları');
        $this->title('Yazılan', [], Suggestion::APPROVED);
        $this->title('Okunacak', ['article' => ['title' => 'Okunacak']], Suggestion::APPROVED);
        $write = ExternalWriteAction::query()->create(['channel' => 'wordpress', 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS, 'status' => 'succeeded',
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'requested_by' => $this->admin->id, 'request_payload' => [],
            'result' => ['post_id' => 31, 'posts' => [['post_id' => 31], ['post_id' => 32]]]]);
        $this->title('Gönderilen', ['article_write_id' => $write->id], Suggestion::APPROVED);
        $live = $this->page('/implant-fiyatlari/', 'İmplant fiyatları', ['category' => 'blog', 'wp_post_id' => 31]);
        DB::table('gsc_page_daily')->insert(['digital_asset_id' => $this->site->id, 'site_url' => 'sc-domain:panorama.com.tr', 'reporting_date' => now()->subDays(3)->toDateString(),
            'page' => $live->url, 'clicks' => 12, 'impressions' => 300, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => md5('x')]);

        $coverage = app(ContentCoverage::class);
        $row = $coverage->rows()[0];
        $this->assertSame([1, 1, 1], [$row['writing'], $row['reading'], $row['sent']]);
        $this->assertSame('1 yazı okumanı bekliyor', ContentCoverage::stage($row)['label']);
        $this->assertSame([$this->site->id => ['sent' => 2, 'live' => 1, 'clicks' => 12]], $coverage->outcomes([$this->site->id]));

        Livewire::test(WorkPage::class)->assertSee('1 yazı okumanı bekliyor')->assertSee('1/2 yayında')->assertSee('12 tıklama')
            ->assertSee('Onayını bekleyen')->assertSee('Sistemin bu hafta yaptığı')->assertSee('Senin elin gerekiyor');
    }

    public function test_every_morning_fills_each_language_to_the_pool_and_monday_adds_fresh_ideas(): void
    {
        Queue::fake();
        $this->travelTo(now('Europe/Istanbul')->next('Tuesday')->setTime(9, 17));
        $this->artisan('moxdop:content:weekly-titles')->assertSuccessful();
        Queue::assertNothingPushed(); // no matched cluster: nothing to plan from

        $this->rowOf('sufficient', 'Ankara implant');
        $this->title('Bir');
        $this->artisan('moxdop:content:weekly-titles')->assertSuccessful();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->siteId === $this->site->id
            && $job->operation === SiteOperations::WEEKLY_CONTENT && $job->params === ['wants' => ['tr' => 19]]);

        // A full pool waits for approvals on weekdays; on Monday it still gets the weekly fresh ideas.
        Queue::fake();
        foreach (range(2, 20) as $n) {
            $this->title('Fikir '.$n);
        }
        $this->artisan('moxdop:content:weekly-titles')->assertSuccessful();
        Queue::assertNothingPushed();
        Livewire::test(WorkPage::class)->assertDontSeeHtml('data-make-titles');
        $this->artisan('moxdop:content:weekly-titles', ['--weekly' => true])->assertSuccessful();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->params === ['wants' => ['tr' => 4]]);
    }

    public function test_fikir_uret_fills_the_pool_now(): void
    {
        Queue::fake();
        Livewire::test(WorkPage::class)->call('makeTitles', $this->site->id)->assertSee('kümeleri eşleşmedi');
        Queue::assertNothingPushed();

        $this->rowOf('no_page', 'İmplant fiyatları');
        Livewire::test(WorkPage::class)->call('makeTitles', $this->site->id)->assertSee('TR 20 fikir hazırlanıyor');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WEEKLY_CONTENT && $job->params['wants'] === ['tr' => 20]);
    }

    public function test_ideas_are_planned_only_in_the_main_language_of_a_site(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->page('/implant/', 'İmplant', ['category' => 'hizmet', 'language' => 'tr']);
        $this->page('/en/dental-implant/', 'Dental Implant', ['category' => 'hizmet', 'language' => 'en']);
        $this->rowOf('no_page', 'İmplant fiyatları');
        $prompts = [];
        $clusterId = (int) BrandClusterPage::query()->value('cluster_id');
        WeeklyContentAgent::fake(function (string $prompt) use (&$prompts, $clusterId): array {
            $prompts[] = $prompt;
            $item = fn (string $language, string $title): array => ['language' => $language, 'title' => $title, 'kind' => 'new', 'cluster_id' => $clusterId, 'page_type' => 'blog',
                'target_url' => null, 'angle' => 'decision', 'outline' => ['Giriş'], 'questions' => ['Soru?'], 'reason' => 'Talep var.'];

            return ['items' => [$item('tr', 'İmplant mı köprü mü'), $item('tr', 'İmplant kimlere uygun'), $item('tr', 'Fazla Türkçe fikir'), $item('en', 'Implant or bridge'), $item('de', 'Implantat')]];
        });

        $this->assertSame(['status' => 'ready', 'added' => 2], app(ContentPlanner::class)->weekly($this->site, null, ['tr' => 2, 'en' => 1]));

        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('"languages":{"tr":2}', $prompts[0], 'English is never planned on its own');
        $this->assertSame(['tr' => 2], app(ContentCoverage::class)->rows()[0]['pool']);
        $this->assertSame(0, Suggestion::query()->where('title', 'Implant or bridge')->count());
    }

    public function test_ideas_rest_on_the_brands_own_searches_and_generated_looking_titles_are_left_out(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->page('/implant/', 'İmplant', ['category' => 'hizmet', 'language' => 'tr']);
        $this->rowOf('no_page', 'Zirkonyum kaplama fiyatı');
        $this->rowOf('no_page', 'İmplant fiyatları');
        $clusters = Cluster::query()->pluck('id', 'name');
        Cluster::query()->whereKey($clusters['Zirkonyum kaplama fiyatı'])->update(['service_id' => $this->zirkonyum->id]);
        DB::table('queries')->update(['impressions' => 100]);
        $this->fact('implant ağrı yapar mı', '/implant/', 400, 6, 9.0);
        $prompts = [];
        WeeklyContentAgent::fake(function (string $prompt) use (&$prompts, $clusters): array {
            $prompts[] = $prompt;
            $item = fn (string $title, ?int $cluster, ?string $query): array => ['language' => 'tr', 'title' => $title, 'kind' => 'new', 'cluster_id' => $cluster, 'query' => $query,
                'page_type' => 'blog', 'target_url' => null, 'angle' => 'objection', 'outline' => ['Giriş'], 'questions' => ['Soru?'], 'reason' => 'Talep var.'];

            return ['items' => [
                $item('İmplant ağrı yapar mı?', null, 'implant ağrı yapar mı'),
                $item('İmplant fiyatları: kapsamlı rehber', $clusters['İmplant fiyatları'], null),
                $item('Diş beyazlatma evde yapılır mı', null, null),
                $item('İmplant tedavisi kaç seansta biter?', $clusters['İmplant fiyatları'], null),
            ]];
        });

        $this->assertSame(['status' => 'ready', 'added' => 2], app(ContentPlanner::class)->weekly($this->site, null, ['tr' => 5]));

        $this->assertStringContainsString('"search_console":[{"query":"implant ağrı yapar mı","impressions":400,"clicks":6', $prompts[0]);
        $this->assertLessThan(strpos($prompts[0], 'Zirkonyum kaplama fiyatı'), strpos($prompts[0], '"name":"İmplant fiyatları"'), 'the main service comes first');
        $evidence = Suggestion::query()->where('title', 'İmplant ağrı yapar mı?')->sole()->evidence;
        $this->assertSame('«implant ağrı yapar mı» 28 günde 400 gösterim, 6 tıklama, ortalama 9,0. sıra', $evidence[0]['value']);
        $this->assertStringContainsString('sorgu kütüphanesinde 100 gösterim', Suggestion::query()->where('title', 'İmplant tedavisi kaç seansta biter?')->sole()->evidence[0]['value']);
        $this->assertSame(0, Suggestion::query()->whereIn('title', ['İmplant fiyatları: kapsamlı rehber', 'Diş beyazlatma evde yapılır mı'])->count(), 'two-part title and no evidence');

        $this->assertNotSame([], app(ContentCoverage::class)->needs(), 'some ideas came: tomorrow tries the next clusters');
    }

    /**
     * yakup, 2026-10-07: pools stuck at 1–4 of 20. Each top-up shows the AI the clusters without an idea first, and only a
     * run that found nothing at all pauses the daily top-up (3 days or Monday), with the reason on the page.
     */
    public function test_each_top_up_puts_clusters_without_an_idea_first_and_only_an_empty_run_pauses_the_pool(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->travelTo(now('Europe/Istanbul')->next('Tuesday')->setTime(10, 0));
        $this->page('/implant/', 'İmplant', ['category' => 'hizmet', 'language' => 'tr']);
        $this->rowOf('no_page', 'İmplant fiyatları');
        $this->rowOf('no_page', 'Zirkonyum kaplama fiyatı');
        $clusters = Cluster::query()->pluck('id', 'name');
        DB::table('queries')->update(['impressions' => 10]);
        DB::table('queries')->whereIn('id', DB::table('cluster_queries')->where('cluster_id', $clusters['İmplant fiyatları'])->pluck('query_id'))->update(['impressions' => 5000]);
        $this->title('İmplant fiyatı neden farklı')->forceFill(['cluster_id' => $clusters['İmplant fiyatları']])->save();
        $prompts = [];
        WeeklyContentAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['items' => []];
        });

        $this->assertSame(['status' => 'ready', 'added' => 0], app(ContentPlanner::class)->weekly($this->site, null, ['tr' => 19]));

        $this->assertLessThan(strpos($prompts[0], '"name":"İmplant fiyatları"'), strpos($prompts[0], '"name":"Zirkonyum kaplama fiyatı"'), 'the cluster without an idea comes first despite less demand');
        $this->assertSame([], app(ContentCoverage::class)->needs(), 'nothing with evidence: no filler on the next daily top-up');
        $this->assertNotSame([], app(ContentCoverage::class)->needs(weekly: true), 'Monday asks again');
        $this->assertStringContainsString('günlük tamamlama', (string) app(ContentCoverage::class)->rows()[0]['reason']);
        $this->travel(ContentPlanner::SHORT_RUN_DAYS + 1)->days();
        $this->assertNotSame([], app(ContentCoverage::class)->needs());
    }

    public function test_a_brand_without_a_service_is_told_to_add_one(): void
    {
        BrandOffering::query()->where('brand_id', $this->brand->id)->delete();

        $this->assertStringContainsString('etkin hizmeti yok', (string) app(ContentCoverage::class)->rows()[0]['reason']);
    }

    public function test_open_ideas_with_generated_looking_titles_are_closed(): void
    {
        $styled = $this->title('All-on-4 / All-on-6: ömür ve bakım — kapsamlı rehber');
        $labelled = $this->title('Diş röntgeni çeşitleri (güncelleme)');
        $approved = $this->title('Gömülü diş operasyonları: süreç', [], Suggestion::APPROVED);
        $good = $this->title('20 yaş dişi çekildikten sonraki ilk 3 gün');

        $this->assertSame(2, ContentPlanner::retireStyledIdeas());

        $this->assertSame([Suggestion::DISMISSED, Suggestion::DISMISSED, Suggestion::APPROVED, Suggestion::OPEN],
            [$styled->fresh()->status, $labelled->fresh()->status, $approved->fresh()->status, $good->fresh()->status]);
        $this->assertNull(ContentPlanner::styleProblem('Şeffaf plak mı, tel mi? Hekimin teli önerdiği durumlar'));
        $this->assertNull(ContentPlanner::styleProblem('All-on-4 bana uygun mu?'));
    }

    public function test_open_ideas_planned_in_another_language_are_closed(): void
    {
        $this->page('/implant/', 'İmplant', ['category' => 'hizmet', 'language' => 'tr']);
        $this->page('/en/dental-implant/', 'Dental Implant', ['category' => 'hizmet', 'language' => 'en']);
        $english = $this->title('Dental implant aftercare', ['language' => 'en']);
        $written = $this->title('Implant or bridge', ['language' => 'en', 'article' => ['title' => 'Implant or bridge']]);
        $turkish = $this->title('İmplant kimlere uygun', ['language' => 'tr']);
        $plain = $this->title('İmplant sonrası ilk gün');

        $this->assertSame(1, ContentPlanner::retireOtherLanguageIdeas());

        $this->assertSame([Suggestion::DISMISSED, Suggestion::OPEN, Suggestion::OPEN, Suggestion::OPEN],
            [$english->fresh()->status, $written->fresh()->status, $turkish->fresh()->status, $plain->fresh()->status]);
        $this->assertStringContainsString('çevrilir', (string) $english->fresh()->operator_note);
    }
}
