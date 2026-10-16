<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Models\BrandClusterPage;
use App\Models\BrandExpert;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\ClusterLinks;
use App\Services\Site\ContentFormat;
use App\Services\Site\ContentMap;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\ContentAutopilot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Küme haritası ve yeni yazım kuralı (yakup, 2026-10-15): a page the site already has owns its subject (a new idea on it
 * becomes that page's update, a second idea of the same subject closes), the article answers its own question with the
 * length and shape its subject needs and the brand's own facts, the cluster's main page links to it once it is live,
 * and the unsent dated articles are planned and written again.
 */
final class ContentClusterMapTest extends SiteTestCase
{
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

    private static function body(int $words): string
    {
        return '<p>Kanal tedavisi muayene ile başlar.</p><h2>Süreç</h2><p>'.trim(str_repeat('tedavi adımı hekim tarafından anlatılır ', (int) ceil($words / 5))).'</p>';
    }

    public function test_a_page_owns_the_search_it_is_about_and_titles_of_the_same_subject_match(): void
    {
        $process = $this->page('/kanal-tedavisi-nasil-yapiliyor-adim-adim-surec/', 'Kanal Tedavisi Nasıl Yapılıyor? Adım Adım Süreç', ['category' => 'blog']);
        $needle = $this->page('/kanal-tedavisinde-igne/', 'Kanal tedavisinde iğne yapılır mı?', ['category' => 'blog']);

        $this->assertTrue(ContentMap::covers($process, 'kanal tedavisi nasıl yapılır'));
        $this->assertFalse(ContentMap::covers($process, 'kanal tedavisinde iğne yapılır mı'), 'a word the page does not carry');
        $this->assertFalse(ContentMap::covers($needle, 'kanal tedavisi'), 'a narrower page does not own the whole subject');
        $this->assertTrue(ContentMap::sameSubject('All-on-4 ve All-on-6 farkları neler?', 'All-on-4 ile All-on-6 farkları'));
        $this->assertFalse(ContentMap::sameSubject('İmplant sonrası beslenme', 'İmplant sonrası sigara'));

        $map = app(ContentMap::class);
        $this->assertSame($process->id, $map->owner($this->site, 'kanal tedavisi nasıl yapılır', 'Kanal tedavisi nasıl yapılır, sinir alınır mı?')?->id);
        $this->assertNull($map->owner($this->site, 'zirkonyum kaplama ömrü', 'Zirkonyum kaplama ne kadar dayanır?'));
    }

    public function test_the_shape_and_length_come_from_the_subject(): void
    {
        $this->assertSame('comparison', ContentFormat::intent('All-on-4 mü All-on-6 mı, hekim neye göre karar verir?', null, 'blog', null));
        $this->assertSame('quick', ContentFormat::intent('Diş çekimi sonrası yüz şişliği kaç gün sürer?', null, 'blog', null));
        $this->assertSame('process', ContentFormat::intent('Kanal tedavisi nasıl yapılır?', null, 'blog', null));
        $this->assertSame('cost', ContentFormat::intent('İmplant maliyetini hangi kalemler belirler?', null, 'blog', null));
        $this->assertSame('pillar', ContentFormat::intent('İmplant tedavisi', null, 'hizmet', null));
        $this->assertSame('guide', ContentFormat::intent('Kancalı protez kimlere uygun?', null, 'blog', null));

        $this->assertSame([450, 750], ContentFormat::for('Dikiş kaç gün sonra alınır?', null, 'blog', null)['words']);
        $this->assertSame([1760, 2100], ContentFormat::for('Kanal tedavisi nasıl yapılır?', null, 'blog', null, [['word_count' => 2200], ['word_count' => 2000], ['word_count' => 2400]])['words'],
            'longer pages that do well raise the bar, at most 1.5× the shape');
        $this->assertTrue(ContentFormat::tooShort(300, ['words' => [450, 750]]));
        $this->assertFalse(ContentFormat::tooShort(400, ['words' => [450, 750]]));
    }

    public function test_the_writer_gets_its_own_question_the_format_the_cluster_map_and_the_brand_facts_and_a_thin_article_is_written_again(): void
    {
        $this->enableAi();
        BrandExpert::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dt. Ayşe Kaya', 'title' => 'Endodonti', 'is_default' => true]);
        $cluster = $this->cluster($this->implant, 'Kanal tedavisi', ['kanal tedavisi', 'kanal tedavisi ağrılı mı'], ['Ağrı', 'Süreç', 'Fiyat']);
        $main = $this->page('/kanal-tedavisi/', 'Kanal Tedavisi', ['category' => 'hizmet']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $main->id,
            'state' => 'thin_coverage', 'language' => 'tr']);
        $idea = $this->idea('Kanal tedavisinde iğne yapılır mı?', ['query' => 'kanal tedavisinde iğne yapılır mı', 'angle' => 'expert_answer'], Suggestion::APPROVED, $cluster->id);
        $sent = [];
        $answers = [self::body(60), self::body(520)];
        WriteArticleAgent::fake(function (string $prompt) use (&$sent, &$answers): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['title' => 'Kanal tedavisinde iğne yapılır mı?', 'slug' => 'kanal-igne', 'meta_title' => 'Kanal tedavisinde iğne', 'meta_description' => 'Anestezi.',
                'excerpt' => 'Anestezi.', 'html' => array_shift($answers) ?? self::body(10)];
        });

        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($idea->fresh()));

        $plan = $sent[0]['plan'];
        $this->assertSame('kanal tedavisinde iğne yapılır mı', $plan['focus_query'], 'the idea\'s own search, not the cluster\'s');
        $this->assertSame(['Kısa soru', 450, 750], [$plan['format']['type'], $plan['format']['words_min'], $plan['format']['words_max']]);
        $this->assertSame($main->url, $plan['main_page_url'], 'the cluster\'s main page to link to');
        $this->assertSame($main->url, $sent[0]['cluster']['map']['main_page']['url']);
        $this->assertSame(['Dt. Ayşe Kaya · Endodonti'], $sent[0]['brand_facts']['experts']);
        $this->assertCount(2, $sent, 'the thin first article is written once more');
        $this->assertSame('length', $sent[1]['fix'][0]['rule']);
        $action = $idea->fresh()->action;
        $this->assertGreaterThan(500, $action['article_format']['words'], 'the longer one is kept');
        $this->assertContains('Markaya özgü bilgi yok: hekim, şube ya da kliniğin kendi süreci geçmiyor; yazı her sitede yayınlanabilecek kadar genel.', $action['article_seo']);
    }

    public function test_the_autopilot_turns_an_owned_subject_into_the_page_update_and_closes_a_second_idea_of_the_same_subject(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Istanbul'));
        $this->enableAi();
        $page = $this->page('/kanal-tedavisi-nasil-yapiliyor/', 'Kanal Tedavisi Nasıl Yapılıyor?', ['category' => 'blog']);
        $owned = $this->idea('Kanal tedavisi nasıl yapılır, sinir alınır mı?', ['query' => 'kanal tedavisi nasıl yapılır']);
        $first = $this->idea('All-on-4 ve All-on-6 farkları neler?');
        $second = $this->idea('All-on-4 ile All-on-6 farkları');
        $free = $this->idea('Zirkonyum kaplama ne kadar dayanır?');

        $result = app(ContentAutopilot::class)->plan($this->site, $this->admin);

        $this->assertSame(2, $result['planned']);
        $owned = $owned->fresh();
        $this->assertSame(['update', $page->id, Suggestion::OPEN], [$owned->action['kind'], $owned->page_id, $owned->status], 'strengthen the page instead');
        $this->assertArrayNotHasKey('autopilot', $owned->action);
        $this->assertSame(Suggestion::DISMISSED, $second->fresh()->status);
        $this->assertNotNull(data_get($first->fresh()->action, 'autopilot.date'));
        $this->assertNotNull(data_get($free->fresh()->action, 'autopilot.date'));
    }

    public function test_rework_writes_the_unsent_dated_articles_again_and_frees_the_owned_ones(): void
    {
        Queue::fake();
        $this->enableAi();
        $page = $this->page('/kanal-tedavisi-nasil-yapiliyor/', 'Kanal Tedavisi Nasıl Yapılıyor?', ['category' => 'blog']);
        $article = ['title' => 'x', 'html' => '<p>Kısa.</p>', 'language' => 'tr'];
        $dated = $this->idea('Zirkonyum kaplama ne kadar dayanır?', ['article' => $article, 'translations' => ['en' => $article],
            'autopilot' => ['date' => '2026-10-10', 'review' => ['verdict' => 'ok']]], Suggestion::APPROVED);
        $owned = $this->idea('Kanal tedavisi nasıl yapılır?', ['query' => 'kanal tedavisi nasıl yapılır', 'article' => $article, 'autopilot' => ['date' => '2026-10-12']], Suggestion::APPROVED);
        $sent = $this->idea('Gönderilmiş yazı', ['article' => $article, 'article_write_id' => 9, 'autopilot' => ['date' => '2026-10-08']], Suggestion::APPROVED);

        $this->assertSame(['rewrite' => 1, 'update' => 1, 'closed' => 0, 'recheck' => 0], app(ContentAutopilot::class)->rework());

        $action = $dated->fresh()->action;
        $this->assertArrayNotHasKey('article', $action);
        $this->assertArrayNotHasKey('translations', $action);
        $this->assertSame(['date' => '2026-10-10'], $action['autopilot'], 'the date stays, the old check goes');
        $this->assertSame(ContentAutopilot::REWRITE_ROUND, $action['rewrite']);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WRITE_ARTICLE && $job->params === ['suggestion_id' => $dated->id]);
        $this->assertSame($page->id, $owned->fresh()->page_id);
        $this->assertSame('x', $sent->fresh()->action['article']['title'], 'a sent article is left alone');
        $this->assertSame(['rewrite' => 0, 'update' => 0, 'closed' => 0, 'recheck' => 0], app(ContentAutopilot::class)->rework(), 'once per round');

        // The new round asks Claude under a new name: the old round's answer is never reused.
        $prompts = 0;
        WriteArticleAgent::fake(function () use (&$prompts): array {
            $prompts++;

            return ['title' => 'Zirkonyum kaplama ne kadar dayanır?', 'slug' => 'z', 'meta_title' => 'Zirkonyum', 'meta_description' => 'Ömür.', 'excerpt' => 'Ömür.', 'html' => self::body(900)];
        });
        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($dated->fresh()));
        $this->assertSame(1, $prompts);
    }

    public function test_rework_also_writes_the_old_pool_articles_and_rechecks_held_new_ones_once(): void
    {
        Queue::fake();
        $old = ['title' => 'x', 'html' => '<p>Eski kalıp.</p>', 'language' => 'tr'];
        $pool = $this->idea('Zirkonyum kaplama ne kadar dayanır?', ['article' => $old], Suggestion::APPROVED);
        $format = ['type' => 'quick', 'words_min' => 450, 'words_max' => 750, 'words' => 440];
        $held = $this->idea('İmplant sonrası ne yenir?', ['article' => ['title' => 'y', 'html' => '<p>Cevap.</p><h2>Bize ulaşın</h2>', 'language' => 'tr'],
            'translations' => ['en' => ['title' => 'y', 'html' => '<p>Answer.</p><h2>Contact us</h2>']], 'article_format' => $format,
            'autopilot' => ['date' => '2026-10-17', 'review' => ['verdict' => 'wrong', 'reason' => '440 kelime, alt sınır 450.']]], Suggestion::APPROVED);
        $passed = $this->idea('Diş taşı temizliği acıtır mı?', ['article' => ['title' => 'z', 'html' => '<p>Cevap.</p>'], 'article_format' => $format,
            'autopilot' => ['date' => '2026-10-18', 'review' => ['verdict' => 'ok']]], Suggestion::APPROVED);

        $this->assertSame(['rewrite' => 1, 'update' => 0, 'closed' => 0, 'recheck' => 1], app(ContentAutopilot::class)->rework());

        $this->assertArrayNotHasKey('article', $pool->fresh()->action, 'an undated pool article of the old template is written again');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->params === ['suggestion_id' => $pool->id]);
        $action = $held->fresh()->action;
        $this->assertSame(['<p>Cevap.</p>', '<p>Answer.</p>'], [$action['article']['html'], $action['translations']['en']['html']], 'the empty heading goes');
        $this->assertArrayNotHasKey('review', $action['autopilot'], 'checked again');
        $this->assertSame('ok', data_get($passed->fresh()->action, 'autopilot.review.verdict'));
        $this->assertSame(['rewrite' => 0, 'update' => 0, 'closed' => 0, 'recheck' => 0], app(ContentAutopilot::class)->rework(), 'once');
    }

    public function test_a_heading_left_without_text_is_removed(): void
    {
        $html = "<p>Giriş.</p>\n<h2>Süreç</h2>\n<h3>Muayene</h3>\n<p>İlk adım.</p>\n<h3>Boş alt başlık</h3>\n<h2>Boş bölüm</h2>\n<h3>Onun da boş alt başlığı</h3>\n<h2>Sık sorulan sorular</h2>\n<h3>Acır mı?</h3>\n<p>Hayır.</p>\n<h2>Bize ulaşın</h2>";

        $this->assertSame("<p>Giriş.</p>\n<h2>Süreç</h2>\n<h3>Muayene</h3>\n<p>İlk adım.</p>\n<h2>Sık sorulan sorular</h2>\n<h3>Acır mı?</h3>\n<p>Hayır.</p>", ContentFormat::dropEmptySections($html));
    }

    public function test_a_live_article_gets_a_link_from_its_cluster_main_page_and_an_unseen_one_a_strengthen_idea(): void
    {
        $cluster = $this->cluster($this->implant, 'Kanal tedavisi', ['kanal tedavisi']);
        $main = $this->page('/kanal-tedavisi/', 'Kanal Tedavisi', ['category' => 'hizmet']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $main->id,
            'state' => 'sufficient', 'language' => 'tr']);
        $write = ExternalWriteAction::query()->create(['channel' => 'wordpress', 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS, 'status' => 'succeeded',
            'digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'requested_by' => $this->admin->id, 'request_payload' => [], 'result' => ['post_id' => 41]]);
        $idea = $this->idea('Kanal tedavisinde iğne yapılır mı?', ['article_write_id' => $write->id], Suggestion::APPROVED, $cluster->id);
        $idea->forceFill(['resolved_at' => now()->subDays(100)])->save();
        $live = $this->page('/kanal-tedavisinde-igne/', 'Kanal tedavisinde iğne yapılır mı?', ['category' => 'blog', 'wp_post_id' => 41]);

        $this->assertSame(['links' => 1, 'weak' => 1], app(ClusterLinks::class)->run());

        $link = Suggestion::query()->where('action_type', 'internal_links')->sole();
        $this->assertSame([$main->id, $live->url], [(int) $link->page_id, $link->action['link_to']]);
        $weak = Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('page_id', $live->id)->sole();
        $this->assertSame(['update', 'weak_article'], [$weak->action['kind'], $weak->action['source']]);
        $this->assertSame(['links' => 0, 'weak' => 0], app(ClusterLinks::class)->run(), 'never twice');

        // A page Google shows is not flagged; a main page that already links is left alone.
        Suggestion::query()->whereKey([$link->id, $weak->id])->delete();
        DB::table('gsc_page_daily')->insert(['digital_asset_id' => $this->site->id, 'site_url' => 'sc-domain:panorama.com.tr', 'reporting_date' => now()->subDays(3)->toDateString(),
            'page' => $live->url, 'clicks' => 0, 'impressions' => 40, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => md5('seen')]);
        DB::table('website_link_edge')->insert(['digital_asset_id' => $this->site->id, 'edge_key' => hash('sha256', 'e'), 'source_url' => $main->url, 'target_url' => $live->url,
            'normalized_target_url' => $live->url, 'is_internal' => true, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'edge')]);
        $this->assertSame(['links' => 0, 'weak' => 0], app(ClusterLinks::class)->run());
    }

    public function test_the_idea_pool_strengthens_the_page_that_owns_a_subject_instead_of_writing_a_rival(): void
    {
        $this->enableAi();
        Queue::fake();
        $page = $this->page('/kanal-tedavisi-nasil-yapiliyor/', 'Kanal Tedavisi Nasıl Yapılıyor?', ['category' => 'blog']);
        $cluster = $this->cluster($this->implant, 'Kanal tedavisi süreci', ['kanal tedavisi nasıl yapılır']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page', 'language' => 'tr']);
        $this->idea('Zirkonyum kaplama kaç yıl dayanır?', ['query' => 'zirkonyum kaplama ömrü']);
        WeeklyContentAgent::fake(fn (string $prompt): array => ['items' => array_map(fn (array $c): array => ['candidate_id' => $c['candidate_id'], 'language' => 'tr',
            'title' => 'Kanal tedavisi adım adım nasıl ilerler?', 'kind' => 'new', 'cluster_id' => null, 'query' => null, 'page_type' => 'blog', 'target_url' => null,
            'angle' => 'process', 'outline' => ['Muayene', 'Temizlik', 'Dolgu'], 'questions' => ['Kaç seans sürer?'], 'reason' => 'Talep var.'],
            json_decode(substr($prompt, strpos($prompt, '{')), true)['candidates'] ?? [])]);

        $this->assertSame(['status' => 'ready', 'added' => 1], app(ContentPlanner::class)->weekly($this->site, null, ['tr' => 1]));

        $stored = Suggestion::query()->where('title', 'Kanal tedavisi adım adım nasıl ilerler?')->sole();
        $this->assertSame(['update', $page->url, 'update'], [$stored->action['kind'], $stored->action['target_url'], $stored->action['angle']]);
        $this->assertSame($page->id, (int) $stored->page_id);
        $this->assertNotEmpty($stored->action['map_note']);
    }
}
