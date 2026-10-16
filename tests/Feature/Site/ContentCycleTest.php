<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ContentBriefAgent;
use App\Ai\Agents\Site\ContentReviewAgent;
use App\Ai\Agents\Site\TopicMapAgent;
use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Repair\DailySendStrip;
use App\Livewire\Operator\Website\V2\ContentTab;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\ContentSiteState;
use App\Models\ContentTopic;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\ClusterLinks;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ContentTopics;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\ContentAutopilot;
use App\Services\Work\ContentRhythm;
use App\Services\Work\ContentScorecard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Haftalık içerik döngüsü (yakup, 2026-10-16 "sırayla tüm yol haritasını yap"): the back-dated fill only for sites
 * without an own post in 60 days, the weekly rhythm by map stage, topic maps per service, Wednesday's ideas from them,
 * Monday's planning (Mon / Wed / Fri), the brief before writing, translations only where the language has demand, sibling
 * links and refresh ideas, the scorecard.
 */
final class ContentCycleTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Istanbul'));
    }

    private function wpPost(DigitalAsset $site, string $date, string $objectId): void
    {
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $site->id, 'cms' => 'wordpress', 'object_id' => $objectId, 'object_type' => 'post',
            'status' => 'publish', 'published_at' => $date.' 08:00:00', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(30), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function write(DigitalAsset $site, int $postId): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create(['channel' => 'wordpress', 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS, 'status' => 'succeeded',
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'requested_by' => $this->admin->id, 'request_payload' => [], 'result' => ['post_id' => $postId]]);
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, array $action = [], string $status = Suggestion::OPEN, ?int $clusterId = null): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', $title), 'material_hash' => 'm',
            'title' => $title, 'reason' => 'Sorgu 120 gösterim aldı.', 'priority' => 2, 'action_type' => SiteSuggestionTypes::CONTENT, 'cluster_id' => $clusterId,
            'status' => $status, 'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['Giriş'], 'questions' => []], $action),
            'evidence' => [], 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function row(Cluster $cluster, string $state, ?int $pageId = null): BrandClusterPage
    {
        return BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $pageId,
            'state' => $state, 'language' => 'tr']);
    }

    /** @param  array<string, mixed>  $extra */
    private function topic(Cluster $cluster, string $type, string $title, array $extra = []): ContentTopic
    {
        return ContentTopic::query()->create(array_merge(['website_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'service_id' => $cluster->service_id,
            'cluster_id' => $cluster->id, 'type' => $type, 'question' => $title, 'title' => $title, 'coverage' => 'missing', 'status' => 'open', 'priority' => 1.0,
            'position' => ContentTopic::query()->count() + 1], $extra));
    }

    private function pageStat(string $url, int $impressions, float $position, string $date): void
    {
        DB::table('gsc_page_daily')->insert(['digital_asset_id' => $this->site->id, 'site_url' => 'sc-domain:panorama.com.tr', 'reporting_date' => $date, 'page' => $url,
            'clicks' => 2, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(30),
            'metadata' => json_encode(['provider_average_position' => $position])]);
    }

    public function test_the_back_dated_fill_stays_only_on_sites_without_an_own_post_in_sixty_days_and_ends_once(): void
    {
        // Panorama posted itself ten days ago: no fill, its unsent dated idea waits for the week.
        $this->wpPost($this->site, '2026-10-04', '7');
        $dated = $this->idea('İmplant sonrası beslenme', ['autopilot' => ['date' => '2026-09-20', 'planned_at' => now()->toIso8601String()]], Suggestion::APPROVED);
        // The second site only has posts MoxDOP wrote: it keeps the fill.
        $quiet = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'ikinci.example', 'domain' => 'ikinci.example', 'primary_url' => 'https://ikinci.example/']);
        $this->wpPost($quiet, '2026-10-10', '55');
        $this->write($quiet, 55);

        $this->assertSame(['eligible' => 1, 'off' => 1, 'freed' => 1], app(ContentAutopilot::class)->scopeBackfill());

        $this->assertSame(ContentSiteState::BACKFILL_OFF, ContentSiteState::for($this->site)->backfill);
        $this->assertSame(ContentSiteState::BACKFILL_ELIGIBLE, ContentSiteState::for($quiet)->backfill);
        $autopilot = $dated->fresh()->action['autopilot'];
        $this->assertArrayNotHasKey('date', $autopilot);
        $this->assertArrayHasKey('freed_at', $autopilot);
        $this->assertSame([], app(ContentAutopilot::class)->openDates($this->site), 'no back-dated dates once off');
        $this->assertSame(['eligible' => 0, 'off' => 0, 'freed' => 0], app(ContentAutopilot::class)->scopeBackfill(), 'scoped once, never again');

        // The quiet site's dates run from two days after its newest post up to today; with no date left the fill is done.
        $this->assertSame(['2026-10-12', '2026-10-14'], app(ContentAutopilot::class)->openDates($quiet));
        $this->wpPost($quiet, '2026-10-13', '56');
        $this->assertSame([], app(ContentAutopilot::class)->openDates($quiet));
        $this->assertSame(ContentSiteState::BACKFILL_DONE, ContentSiteState::for($quiet)->backfill);
        $this->assertNotNull(ContentSiteState::for($quiet)->backfill_done_at);
    }

    public function test_the_weekly_rhythm_follows_the_stage_of_the_map_and_the_brand_number_is_only_a_ceiling(): void
    {
        $clusters = collect(range(1, 7))->map(fn (int $i): Cluster => $this->cluster($i <= 4 ? $this->implant : $this->zirkonyum, 'Küme '.$i, ['sorgu '.$i]));
        $rows = $clusters->map(fn (Cluster $c, int $i): BrandClusterPage => $this->row($c, $i < 2 ? 'sufficient' : 'no_page'));
        $rhythm = app(ContentRhythm::class);

        $this->assertSame(['stage' => 'empty', 'new' => 3, 'update' => 1], array_intersect_key($rhythm->for($this->site), array_flip(['stage', 'new', 'update'])));

        $this->brand->forceFill(['weekly_content_capacity' => 2])->save();
        $this->assertSame([2, 2], [$rhythm->for($this->site->fresh())['new'], $rhythm->for($this->site->fresh())['update']], 'the ceiling moves the rest to updates');
        $this->brand->forceFill(['weekly_content_capacity' => 4])->save();

        $rows->slice(2, 2)->each(fn (BrandClusterPage $r) => $r->forceFill(['state' => 'sufficient'])->save());
        $this->assertSame(['half', 2, 2], array_values(array_intersect_key($rhythm->for($this->site->fresh()), array_flip(['stage', 'new', 'update']))));

        $rows->slice(4, 2)->each(fn (BrandClusterPage $r) => $r->forceFill(['state' => 'sufficient'])->save());
        $this->assertSame(['full', 1, 3], array_values(array_intersect_key($rhythm->for($this->site->fresh()), array_flip(['stage', 'new', 'update']))));

        // Never more new articles than empty places.
        $rows->last()->forceFill(['state' => 'sufficient'])->save();
        $this->assertSame(0, $rhythm->for($this->site->fresh())['new']);

        // A narrow local business: at most one new article a week.
        $rows->slice(3)->each(fn (BrandClusterPage $r) => $r->delete());
        $rows->first()->forceFill(['state' => 'no_page'])->save();
        $stored = $rhythm->store($this->site->fresh());
        $this->assertSame(['local', 1, 1], [$stored['stage'], $stored['new'], $stored['update']]);
        $this->assertSame([1, 1, 'local'], [ContentSiteState::for($this->site)->new_per_week, ContentSiteState::for($this->site)->updates_per_week, ContentSiteState::for($this->site)->stage]);
    }

    public function test_the_topic_map_keeps_what_passes_the_rules_in_writing_order_and_the_most_valuable_service_goes_first(): void
    {
        $this->enableAi();
        $main = $this->page('/implant-tedavisi/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $pillar = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        $after = $this->cluster($this->implant, 'İmplant sonrası', ['implant sonrası ne yenir']);
        $this->row($pillar, 'sufficient', $main->id);
        $this->row($after, 'no_page');
        $zirkonyum = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        $this->row($zirkonyum, 'no_page');
        $sent = [];
        TopicMapAgent::fake(function (string $prompt) use (&$sent, $main): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $topic = fn (string $type, string $title, array $extra = []): array => array_merge(['type' => $type, 'question' => $title, 'title' => $title, 'cluster_id' => null,
                'covered_by_url' => null, 'coverage' => 'missing', 'season_month' => null], $extra);

            return ['topics' => [
                $topic('pillar', 'İmplant tedavisi nasıl yapılır', ['covered_by_url' => $main->url, 'coverage' => 'covered']),
                $topic('pillar', 'İmplant tedavisinin bütün aşamaları'),
                $topic('fear', 'İmplant takılırken ağrı olur mu'),
                $topic('process', 'İmplant sonrası ilk hafta beslenme'),
                $topic('abroad', 'Yurt dışından implant için gelmek'),
                $topic('local', 'Çankaya implant kliniği seçerken'),
                $topic('seasonal', 'Yılbaşı öncesi implant planı'),
                $topic('seasonal', 'Kasımda implant için doğru zaman', ['season_month' => 11]),
                $topic('fear', 'İmplant takılırken ağrı olur mu'),
                $topic('blog', 'İmplant bakım listesi'),
            ]];
        });
        $topics = app(ContentTopics::class);

        $this->assertSame([$this->implant->id], $topics->activeServices($this->site, $this->brand), 'the main service is worth most; one service on a small site');
        $this->assertSame(['status' => 'ready', 'topics' => 5], $topics->ensureMap($this->site, $this->brand, $this->implant->id));
        $this->assertSame(['status' => 'ready'], $topics->ensureMap($this->site, $this->brand, $this->implant->id), 'a fresh map is not drawn again');
        $this->assertCount(1, $sent);
        $this->assertSame(['Çankaya'], $sent[0]['branch_places']);
        $this->assertFalse($sent[0]['serves_abroad']);

        $map = ContentTopic::query()->orderBy('position')->get();
        $this->assertSame(['pillar', 'process', 'fear', 'local', 'seasonal'], $map->pluck('type')->all(), 'second pillar, abroad, undated seasonal, a duplicate and an unknown type dropped');
        $this->assertSame(['covered', $main->id], [$map[0]->coverage, $map[0]->page_id]);
        $this->assertSame(11, $map[4]->season_month);

        // The week: a seasonal topic due in 4–6 weeks goes first, then the writing order.
        $next = $topics->next($this->site, [$this->implant->id], 2, 1);
        $this->assertSame(['Kasımda implant için doğru zaman', 'İmplant sonrası ilk hafta beslenme'], $next['new']->pluck('title')->all());

        // Once every topic is done the next service starts.
        ContentTopic::query()->update(['status' => 'live']);
        $this->assertSame([$this->zirkonyum->id], $topics->activeServices($this->site, $this->brand));
        $this->assertSame([$this->zirkonyum->id], ContentSiteState::for($this->site)->active_service_ids);
        $completion = $topics->completion($this->site);
        $this->assertSame([100, 5, 0], [$completion[0]['percent'], $completion[0]['live'], $completion[0]['waiting']]);
    }

    public function test_wednesdays_ideas_come_from_the_active_map_and_the_map_follows_what_becomes_of_them(): void
    {
        $this->enableAi();
        $after = $this->cluster($this->implant, 'İmplant sonrası', ['implant sonrası ne yenir']);
        $this->row($after, 'no_page');
        $first = $this->topic($after, 'process', 'İmplant sonrası ilk hafta beslenme');
        $second = $this->topic($after, 'fear', 'İmplant takılırken ağrı olur mu');
        $this->topic($after, 'decision', 'İmplant mı köprü mü seçilmeli', ['status' => 'dropped']);
        $prompts = [];
        WeeklyContentAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;
            $candidates = json_decode(substr($prompt, strpos($prompt, '{')), true)['candidates'];

            return ['items' => array_map(fn (array $c): array => ['candidate_id' => $c['candidate_id'], 'language' => 'tr', 'title' => $c['topic_title'] ?? 'Başka fikir',
                'kind' => $c['kind'], 'cluster_id' => null, 'query' => null, 'page_type' => 'blog', 'target_url' => null, 'angle' => 'process', 'outline' => ['Giriş', 'Süreç'],
                'questions' => [], 'reason' => 'Konu haritasında boş.'], $candidates)];
        });

        $result = app(ContentPlanner::class)->weekly($this->site, null, null, ['new' => 2, 'update' => 0]);

        $this->assertSame('ready', $result['status']);
        $this->assertStringContainsString('"source":"topic"', $prompts[0]);
        $ideas = Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $ideas->map(fn (Suggestion $s): int => (int) $s->action['topic_id'])->all());
        $this->assertSame(['2026-10-14', 'process'], [$ideas[0]->action['cycle'], $ideas[0]->action['topic_type']]);

        $topics = app(ContentTopics::class);
        $topics->sync($this->site);
        $this->assertSame(['idea', 'idea'], [$first->fresh()->status, $second->fresh()->status]);
        $this->assertSame($ideas[0]->id, (int) $first->fresh()->suggestion_id);

        // A dismissed idea frees its topic; an idea that went to the site makes it "sent".
        $ideas[1]->forceFill(['status' => Suggestion::DISMISSED])->save();
        $ideas[0]->forceFill(['status' => Suggestion::APPROVED, 'action' => array_merge($ideas[0]->action, ['article_write_id' => $this->write($this->site, 90)->id])])->save();
        $topics->sync($this->site);
        $this->assertSame(['sent', 'open'], [$first->fresh()->status, $second->fresh()->status]);

        // Live once its post is on the site.
        $this->page('/implant-sonrasi-ilk-hafta/', 'İmplant sonrası ilk hafta beslenme', ['category' => 'blog', 'wp_post_id' => 90]);
        $topics->sync($this->site);
        $this->assertSame('live', $first->fresh()->status);
    }

    public function test_monday_dates_the_weeks_new_articles_mon_wed_fri_counting_what_was_sent_by_hand(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 05:00', 'Europe/Istanbul'));
        ContentSiteState::for($this->site)->forceFill(['backfill' => ContentSiteState::BACKFILL_OFF, 'new_per_week' => 4])->save();
        $article = ['title' => 'x', 'html' => '<p>x</p>', 'language' => 'tr'];
        $written = $this->idea('İmplant sonrası beslenme', ['article' => $article], Suggestion::APPROVED);
        $writing = $this->idea('Zirkonyum kaplama ömrü', [], Suggestion::APPROVED);
        $fresh = $this->idea('Diş taşı temizliği sıklığı', ['cycle' => '2026-10-14']);
        $old = $this->idea('Gülüş tasarımı süreci', ['cycle' => '2026-10-07']);
        $update = $this->idea('Kanal tedavisi sayfasını güçlendir', ['kind' => 'update', 'cycle' => '2026-10-14']);
        $byHand = $this->idea('Çocuk diş hekimi seçimi', ['article_write_id' => 1], Suggestion::APPROVED);
        $byHand->forceFill(['resolved_at' => CarbonImmutable::parse('2026-10-16 12:00')])->save();

        $this->assertSame(['planned' => 3, 'quota' => 4], app(ContentAutopilot::class)->planWeek($this->site, $this->admin));

        $this->assertSame(['2026-10-19', '2026-10-21', '2026-10-23'], [$written->fresh()->action['autopilot']['date'], $writing->fresh()->action['autopilot']['date'], $fresh->fresh()->action['autopilot']['date']]);
        $this->assertSame('2026-10-19', $fresh->fresh()->action['autopilot']['week']);
        $this->assertSame(Suggestion::APPROVED, $fresh->fresh()->status);
        $this->assertNull(data_get($old->fresh()->action, 'autopilot'), 'an old pool idea is not this cycle\'s');
        $this->assertNull(data_get($update->fresh()->action, 'autopilot'), 'updates stay manual');
        Queue::assertPushed(RunSiteOperationJob::class, 2);
        $this->assertSame(['planned' => 0, 'quota' => 4], app(ContentAutopilot::class)->planWeek($this->site, $this->admin));
        $this->assertSame('2026-10-19', ContentSiteState::for($this->site)->week_planned->toDateString());
        $this->assertSame(3, app(ContentAutopilot::class)->preparing(), 'written, translated or checked: not reviewed yet');

        // After the check the Monday click sends the whole week: today's goes out shortly, the others on their day.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'Europe/Istanbul'));
        foreach ([$written, $writing] as $idea) {
            $action = $idea->fresh()->action;
            $action['article'] = $article;
            $action['autopilot']['review'] = ['verdict' => 'ok', 'reason' => 'Uygun.'];
            $idea->forceFill(['action' => $action])->save();
        }
        $ready = app(ContentAutopilot::class)->ready();
        $this->assertSame([$written->id, $writing->id], $ready->pluck('id')->all());
        $this->assertSame('2026-10-19 11:15', ContentAutopilot::postDate($ready[0])->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 10:00', ContentAutopilot::postDate($ready[1])->format('Y-m-d H:i'));
        Livewire::test(DailySendStrip::class)->assertSee('2 yazı')->assertSee('bu haftanın 1 yazısı hâlâ yazılıyor');

        // A site still in its back-dated fill waits.
        ContentSiteState::for($this->site)->forceFill(['backfill' => ContentSiteState::BACKFILL_ELIGIBLE])->save();
        $this->assertSame('backfill', app(ContentAutopilot::class)->planWeek($this->site, $this->admin)['reason']);
    }

    public function test_a_new_article_is_written_from_its_brief_and_checked_against_it(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant sonrası', ['implant sonrası ne yenir']);
        $this->row($cluster, 'no_page');
        $idea = $this->idea('İmplant sonrası ilk hafta beslenme', ['query' => 'implant sonrası ne yenir', 'angle' => 'process'], Suggestion::APPROVED, $cluster->id);
        ContentBriefAgent::fake([['searched' => true, 'intent' => 'Tedaviden sonra ne yiyebileceğini öğrenmek.', 'format' => 'Gün gün rehber',
            'top_results' => [['title' => 'İmplant sonrası beslenme', 'url' => 'https://ornek.com/implant-beslenme', 'covers' => ['İlk 24 saat']], ['title' => 'Uydurma', 'url' => 'yok', 'covers' => []]],
            'people_also_ask' => ['İmplanttan sonra kahve içilir mi?'], 'must_cover' => ['İlk 24 saat soğuk ve yumuşak gıdalar'], 'gaps' => ['Sigara ve alkol süresi'],
            'brand_element' => 'Çankaya şubesinin kontrol randevusu', 'outline' => ['İlk 24 saat', 'İlk hafta']]]);
        $sent = [];
        WriteArticleAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['title' => 'İmplant sonrası ilk hafta beslenme', 'slug' => 'implant-beslenme', 'meta_title' => 'İmplant sonrası beslenme', 'meta_description' => 'İlk hafta.',
                'excerpt' => 'İlk hafta.', 'html' => '<p>İmplant sonrası ilk gün soğuk gıdalar yenir.</p><h2>İlk hafta</h2><p>'.str_repeat('yumuşak gıdalar hekim kontrolünde tüketilir ', 220).'</p>'];
        });

        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($idea->fresh()));

        $brief = $idea->fresh()->action['brief'];
        $this->assertTrue($brief['searched']);
        $this->assertSame(['https://ornek.com/implant-beslenme'], array_column($brief['top_results'], 'url'), 'a result without a real address is dropped');
        $this->assertSame(['İlk 24 saat soğuk ve yumuşak gıdalar'], $sent[0]['brief']['must_cover']);

        $action = $idea->fresh()->action;
        $action['autopilot'] = ['date' => '2026-10-14', 'planned_at' => now()->toIso8601String()];
        $idea->forceFill(['action' => $action])->save();
        $prompts = [];
        ContentReviewAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['items' => [['id' => 0, 'verdict' => 'ok', 'reason' => 'Uygun.']]];
        });
        app(ContentAutopilot::class)->review($this->site);
        $this->assertStringContainsString('İlk 24 saat soğuk ve yumuşak gıdalar', $prompts[0] ?? '');
        $this->assertStringContainsString('Sigara ve alkol süresi', $prompts[0] ?? '');
    }

    public function test_an_article_is_translated_only_into_languages_google_shows(): void
    {
        $this->assertTrue(ContentPlanner::languageHasDemand($this->site, 'en'), 'too few pages to judge');
        $pages = collect(range(1, 10))->map(fn (int $i) => $this->page('/en/page-'.$i.'/', 'Page '.$i, ['language' => 'en']));
        $this->assertFalse(ContentPlanner::languageHasDemand($this->site, 'en'), 'ten English pages Google never shows');
        $this->pageStat($pages[0]->url, 12, 18.0, '2026-10-01');
        $this->assertTrue(ContentPlanner::languageHasDemand($this->site, 'en'));
    }

    public function test_earlier_articles_of_the_cluster_link_to_the_new_one_and_a_nearly_there_article_gets_a_refresh_idea(): void
    {
        $cluster = $this->cluster($this->implant, 'Kanal tedavisi', ['kanal tedavisi']);
        $main = $this->page('/kanal-tedavisi/', 'Kanal Tedavisi', ['category' => 'hizmet']);
        $this->row($cluster, 'sufficient', $main->id);
        $older = $this->idea('Kanal tedavisinde iğne yapılır mı?', ['article_write_id' => $this->write($this->site, 41)->id], Suggestion::APPROVED, $cluster->id);
        $older->forceFill(['resolved_at' => now()->subDays(40)])->save();
        $newer = $this->idea('Kanal tedavisi kaç seans sürer?', ['article_write_id' => $this->write($this->site, 42)->id], Suggestion::APPROVED, $cluster->id);
        $newer->forceFill(['resolved_at' => now()->subDays(10)])->save();
        $olderPage = $this->page('/kanal-tedavisinde-igne/', 'Kanal tedavisinde iğne yapılır mı?', ['category' => 'blog', 'wp_post_id' => 41]);
        $newerPage = $this->page('/kanal-tedavisi-kac-seans/', 'Kanal tedavisi kaç seans sürer?', ['category' => 'blog', 'wp_post_id' => 42]);
        $this->pageStat($olderPage->url, 30, 9.0, now()->subDays(3)->toDateString());
        $this->pageStat($olderPage->url, 30, 7.0, now()->subDays(4)->toDateString());

        $this->assertSame(['links' => 2, 'weak' => 0, 'siblings' => 1, 'refresh' => 1], app(ClusterLinks::class)->run());

        $sibling = Suggestion::query()->where('decision_key', 'site.cluster-link')->where('page_id', $olderPage->id)->sole();
        $this->assertSame($newerPage->url, $sibling->action['link_to']);
        $refresh = Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('page_id', $olderPage->id)->sole();
        $this->assertSame(['update', 'striking_article'], [$refresh->action['kind'], $refresh->action['source']]);
        $this->assertStringContainsString('60 gösterim, ortalama 8,0. sıra', $refresh->reason);
        $this->assertSame(['links' => 0, 'weak' => 0, 'siblings' => 0, 'refresh' => 0], app(ClusterLinks::class)->run(), 'never twice');
    }

    public function test_the_scorecard_shows_index_state_and_numbers_and_the_content_tab_shows_the_map(): void
    {
        $cluster = $this->cluster($this->implant, 'Kanal tedavisi', ['kanal tedavisi']);
        $this->row($cluster, 'no_page');
        $idea = $this->idea('Kanal tedavisinde iğne yapılır mı?', ['article_write_id' => $this->write($this->site, 41)->id], Suggestion::APPROVED, $cluster->id);
        $idea->forceFill(['resolved_at' => now()->subDays(20)])->save();
        $page = $this->page('/kanal-tedavisinde-igne/', 'Kanal tedavisinde iğne yapılır mı?', ['category' => 'blog', 'wp_post_id' => 41]);
        $this->pageStat($page->url, 25, 12.0, now()->subDays(5)->toDateString());
        DB::table('gsc_url_inspection_snapshot')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
            'page' => $page->url, 'inspected_at' => now()->subDay(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20), 'metadata' => json_encode(['verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed'])]);
        $lost = $this->idea('Kanal tedavisi kaç seans sürer?', ['article_write_id' => $this->write($this->site, 77)->id], Suggestion::APPROVED, $cluster->id);
        $lost->forceFill(['resolved_at' => now()->subDays(10)])->save();

        $rows = collect(app(ContentScorecard::class)->forSite($this->site))->keyBy('id');

        $this->assertSame('Google dizinde değil: Crawled - currently not indexed', $rows[$idea->id]['flag']);
        $this->assertSame([25, 2, 12.0], [$rows[$idea->id]['d28']['impressions'], $rows[$idea->id]['d28']['clicks'], $rows[$idea->id]['d28']['position']]);
        $this->assertSame('Sitede yayında görünmüyor', $rows[$lost->id]['flag']);

        $this->topic($cluster, 'pillar', 'Kanal tedavisi rehberi', ['status' => 'live', 'page_id' => $page->id]);
        $this->topic($cluster, 'fear', 'Kanal tedavisi ağrılı mı');
        ContentSiteState::for($this->site)->forceFill(['active_service_ids' => [$this->implant->id]])->save();
        Livewire::test(ContentTab::class, ['assetId' => $this->site->id])
            ->assertSee('Konu haritası')->assertSee('şu an yazılıyor')->assertSee('%50 bitti')->assertSee('sırada 1 konu')
            ->set('openService', $this->implant->id)->assertSee('Kanal tedavisi ağrılı mı')->assertSee('yazılacak')
            ->set('showScorecard', true)->assertSee('dizinde değil')->assertSee('Sitede yayında görünmüyor');
    }
}
