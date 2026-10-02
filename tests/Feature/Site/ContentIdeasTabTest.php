<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ApplyChangeAgent;
use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Ai\Agents\Site\ContentRecipeAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Models\BrandClusterPage;
use App\Models\BrandContentIdea;
use App\Models\ContentIdea;
use App\Models\Suggestion;
use App\Services\Compliance\ForbiddenTermsLibrary;
use App\Services\Site\ClusterAudit;
use App\Services\Site\ContentIdeaState;
use App\Services\Site\ContentIdeaSubject;
use App\Services\Site\ContentPlanner;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Web sitesi › İçerik fikirleri (docs/product/CONTENT_IDEAS_BLUEPRINT.md §5, §8). */
final class ContentIdeasTabTest extends SiteTestCase
{
    public function test_state_rules_follow_the_blueprint(): void
    {
        $page = $this->page('/implant/', 'İmplant');
        $score = fn (string $state, ?int $value, ?float $position = 8.0): object => (object) ['state' => $state, 'score' => $value, 'position' => $position];

        $this->assertSame('no_page', ContentIdeaState::resolve(null, 'none', null, null)['state']);
        $this->assertSame('unchecked', ContentIdeaState::resolve($page, null, null, null)['state']);
        $this->assertSame('improve', ContentIdeaState::resolve($page, 'partial', [['text' => 'Süre yok', 'kind' => 'soru']], $score('scored', 80))['state']);
        $this->assertSame('improve', ContentIdeaState::resolve($page, 'full', [], $score('scored', 38))['state']);
        $this->assertSame('sufficient', ContentIdeaState::resolve($page, 'full', [], $score('scored', 50))['state']);
        $this->assertSame('sufficient', ContentIdeaState::resolve($page, 'full', [], $score('low_data', null))['state'], 'veri az: coverage decides');
        $this->assertSame('sufficient', ContentIdeaState::resolve($page, 'full', [], null)['state'], 'no Search Console: coverage decides');
        $improve = ContentIdeaState::resolve($page, 'partial', [['text' => 'Süre yok', 'kind' => 'soru'], ['text' => 'Fiyat etkenleri yok', 'kind' => 'bolum']], $score('scored', 38, 14.2));
        $this->assertSame('2 eksik · Süre yok · ort. sıra 14,2 · puan 38', $improve['reason']);

        // Teknik sorun comes first: noindex, canonical elsewhere, 4xx/5xx of the last fetch.
        $page->forceFill(['is_indexable' => false])->save();
        $technical = ContentIdeaState::resolve($page, 'full', [], $score('scored', 90));
        $this->assertSame(['technical', 'Sayfa noindex — önce indekslemeyi açın'], [$technical['state'], $technical['reason']]);
        $page->forceFill(['is_indexable' => true, 'canonical' => 'https://panorama.com.tr/baska/'])->save();
        $this->assertSame('technical', ContentIdeaState::resolve($page, 'full', [], null)['state']);
        $page->forceFill(['canonical' => 'https://panorama.com.tr/implant/'])->save();
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $this->site->id, 'url' => 'https://panorama.com.tr/implant/', 'status_code' => 404,
            'html_hash' => str_repeat('a', 64), 'change_state' => 'changed', 'html_bytes' => 10, 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('Sayfa son taramada 404 döndü', ContentIdeaState::resolve($page, 'full', [], null)['reason']);

        // Wrong page and conflict from the cluster's Search Console impressions.
        $shares = [['url' => 'https://panorama.com.tr/blog/a/', 'url_key' => 'panorama.com.tr/blog/a', 'impressions' => 60, 'share' => 0.6],
            ['url' => 'https://panorama.com.tr/blog/b/', 'url_key' => 'panorama.com.tr/blog/b', 'impressions' => 40, 'share' => 0.4]];
        $reason = ContentIdeaState::resolve(null, 'none', null, null, $shares)['reason'];
        $this->assertStringContainsString('Google bu kümede /blog/a/ sayfasını gösteriyor', $reason);
        $this->assertStringContainsString('/blog/a/ ve /blog/b/ aynı kümede yarışıyor', $reason);
    }

    public function test_matching_puts_the_search_console_page_first_never_offers_home_or_contact_and_keeps_extra_ideas_off_the_main_page(): void
    {
        $this->enableAi();
        $service = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'content_text' => 'İmplant tedavisi anlatılır.', 'wp_post_id' => 41]);
        $this->page('/', 'Panorama Diş', ['category' => null, 'content_text' => 'İmplant tedavisi ve diğer hizmetler.']);
        $this->page('/iletisim/', 'İletişim implant', ['category' => null]);
        $lead = $this->page('/blog/yazi-12/', 'Diş kliniği notları', ['category' => 'blog', 'content_text' => 'Genel yazı.']);
        $nutrition = $this->page('/blog/implant-sonrasi-beslenme/', 'İmplant sonrası beslenme', ['category' => 'blog', 'content_text' => 'İlk gün ılık çorba.',
            'headings' => [['level' => 2, 'text' => 'İlk gün'], ['level' => 3, 'text' => 'Çorba']]]);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nasıl yapılır']);
        $this->fact('implant tedavisi', '/blog/yazi-12/', 900, 10, 7.0);
        $this->fact('implant tedavisi', '/implant/', 100, 5, 12.0);
        $idea = ContentIdea::query()->create(['cluster_id' => $cluster->id, 'title' => 'İmplant sonrası beslenme rehberi', 'title_key' => 'implant sonrasi beslenme rehberi',
            'type' => 'guide', 'angle' => 'İlk haftalarda ne yenir.', 'target_queries' => [['text' => 'implant nasıl yapılır', 'in_cluster' => true], ['text' => 'implant sonrası beslenme', 'in_cluster' => false]],
            'outline' => ['İlk gün', 'İlk hafta', 'Kaçınılacaklar']]);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $service->id, 'state' => 'sufficient', 'locked' => true, 'language' => 'tr']);

        $calls = ['match' => [], 'gaps' => []];
        ClusterAiQueriesAgent::fake(fn (): array => ['clusters' => [['cluster_id' => $cluster->id, 'questions' => ['İmplant kaç günde biter?']]]]);
        ClusterMatchAgent::fake(function (string $prompt) use (&$calls, $service, $nutrition): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $calls['match'][] = $data;
            $first = $data['clusters'][0];

            return ['clusters' => [($first['kind'] ?? null) === 'extra'
                ? ['cluster_id' => $first['cluster_id'], 'page_id' => $nutrition->id, 'coverage' => 'partial', 'reason' => 'Beslenme anlatılıyor.']
                : ['cluster_id' => $first['cluster_id'], 'page_id' => $service->id, 'coverage' => 'full', 'reason' => 'Tedavi anlatılıyor.']]];
        });
        ClusterGapsAgent::fake(function (string $prompt) use (&$calls): array {
            $data = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);
            $calls['gaps'][] = $data;

            return ['clusters' => [['cluster_id' => $data['clusters'][0]['cluster_id'], 'coverage' => 'partial', 'gaps' => [['text' => 'Kaçınılacak gıdalar listesi yok', 'kind' => 'bolum']]]]];
        });

        $result = app(ClusterAudit::class)->run($this->site);

        $this->assertSame(1, $result['ideas']);
        $mainPages = array_column($calls['match'][0]['pages'], 'url');
        $this->assertSame('https://panorama.com.tr/blog/yazi-12/', $mainPages[0], 'the page with ≥ 50 % of the impressions is the first candidate');
        $this->assertNotContains('https://panorama.com.tr/', $mainPages, 'home page is never a candidate');
        $this->assertNotContains('https://panorama.com.tr/iletisim/', $mainPages, 'contact page is never a candidate');
        $this->assertSame($service->id, BrandClusterPage::query()->sole()->page_id, 'the operator\'s page is kept');

        $extraCall = $calls['match'][1];
        $this->assertSame(['extra', 'İmplant sonrası beslenme rehberi', 'https://panorama.com.tr/implant/'], [$extraCall['clusters'][0]['kind'], $extraCall['clusters'][0]['name'], $extraCall['clusters'][0]['main_page_url']]);
        $this->assertNotContains('https://panorama.com.tr/implant/', array_column($extraCall['pages'], 'url'), 'the main idea\'s page is never a candidate of an extra idea');
        $this->assertSame('https://panorama.com.tr/blog/implant-sonrasi-beslenme/', $extraCall['pages'][0]['url'], 'a page of the idea\'s type (blog for a guide) first');
        $this->assertSame(['İlk gün', 'Çorba'], $extraCall['pages'][0]['headings'], 'stored {level, text} headings are read as texts');
        $this->assertSame(['İlk gün', 'İlk hafta', 'Kaçınılacaklar'], end($calls['gaps'])['clusters'][0]['subtopics']);
        $usage = BrandContentIdea::query()->sole();
        $this->assertSame([$nutrition->id, 'partial', 'improve'], [$usage->page_id, $usage->coverage, $usage->state]);

        $this->artisan('moxdop:clusters:score-pages')->assertSuccessful();
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])
            ->assertSee('İmplant tedavisi')->assertSee('↳ İmplant sonrası beslenme rehberi')->assertSee('/blog/implant-sonrasi-beslenme/')
            ->assertSee('Kaçınılacak gıdalar listesi yok')->assertSee('Google bu kümede /blog/yazi-12/ sayfasını gösteriyor')
            ->set('state', 'improve')->assertSee('↳ İmplant sonrası beslenme rehberi')
            ->set('state', 'no_page')->assertDontSee('↳ İmplant sonrası beslenme rehberi');

        // The operator's page for an extra idea is kept by the next match.
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->set("ideaPage.{$usage->id}", (string) $lead->id)->call('saveIdea', $usage->id);
        $this->assertTrue($usage->fresh()->locked);
        app(ClusterAudit::class)->ideas($this->site, $this->brand);
        $this->assertSame($lead->id, $usage->fresh()->page_id);
    }

    public function test_seo_analysis_builds_a_checked_recipe_and_ai_ile_gelistir_and_uret_apply_it(): void
    {
        $this->enableAi();
        $guide = $this->page('/blog/implant-sonrasi/', 'İmplant sonrası', ['category' => 'blog', 'wp_post_id' => 52, 'is_indexable' => false,
            'content_text' => 'İmplant sonrası ilk gün dinlenin. 420 kelimelik kısa yazı.', 'word_count' => 420]);
        $main = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $cluster = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası ağrı', 'implant sonrası ne yenir'], ['ağrı', 'beslenme'], 'informational');
        $cluster->forceFill(['page_type' => 'guide'])->save();
        $row = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $guide->id, 'state' => 'thin_coverage', 'language' => 'tr']);
        $row->forceFill(['coverage' => 'partial', 'gaps' => [['text' => 'Ağrı kaç gün sürer yanıtlanmamış', 'kind' => 'soru']], 'audited_at' => now()])->save();
        $this->fact('implant sonrası ağrı', '/blog/implant-sonrasi/', 620, 12, 11.8);

        $sent = [];
        ContentRecipeAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['summary' => 'Sayfa 420 kelime; ağrı sorusu yanıtlanmıyor.', 'steps' => [
                ['order' => 1, 'area' => 'bolum', 'action' => 'Ağrı kaç gün sürer bölümü ekle', 'where' => 'İlk günler başlığının altına', 'why' => 'implant sonrası ağrı 620 gösterim, 11,8. sırada', 'evidence' => ['620 gösterim']],
                ['order' => 2, 'area' => 'soru_cevap', 'action' => 'Sık sorulan 6 soru ekle', 'where' => 'Sayfa sonu', 'why' => 'Uydurma 7.777 rakam', 'evidence' => []],
                ['order' => 3, 'area' => 'baska', 'action' => 'Geçersiz alan', 'where' => '-', 'why' => '-', 'evidence' => []],
            ], 'seo_title' => 'İmplant Sonrası Bakım Rehberi: Ağrı, Şişlik ve Beslenme | Panorama Ankara', 'meta_description' => 'İmplant sonrası ağrı ve beslenme.',
                'expected_effect' => 'Ağrı sorgularında ilk sayfaya yaklaşma.', 'measure_after_days' => 56];
        });

        $tab = Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->call('recipe', 'main', $row->id);
        $pack = $sent[0];
        $this->assertSame(['main', 'guide', ['ağrı', 'beslenme']], [$pack['idea']['kind'], $pack['idea']['type'], $pack['idea']['subtopics']]);
        $this->assertSame(['status' => null, 'indexable' => false, 'canonical_ok' => true, 'issues' => ['Sayfa noindex — önce indekslemeyi açın']], $pack['page']['technical']);
        $this->assertSame([['query' => 'implant sonrası ağrı', 'position' => 11.8, 'impressions' => 620, 'clicks' => 12]], $pack['search_console_top_queries']);
        $this->assertSame([], $pack['benchmarks']);
        $this->assertContains('https://panorama.com.tr/implant/', array_column($pack['site_pages'], 'url'));
        $this->assertNotContains('https://panorama.com.tr/blog/implant-sonrasi/', array_column($pack['site_pages'], 'url'));

        $recipe = $row->fresh()->recipe;
        $this->assertSame(['teknik', 'bolum'], array_column($recipe['steps'], 'area'), 'technical problem first; invented number and unknown area dropped');
        $this->assertSame([1, 2], array_column($recipe['steps'], 'order'));
        $this->assertSame('Sayfa 420 kelime; ağrı sorusu yanıtlanmıyor.', $recipe['summary']);
        $this->assertNull($recipe['seo_title'], 'longer than 60 characters');
        $tab->call('toggle', 'main-'.$row->id.':recipe')->assertSee('Ağrı kaç gün sürer bölümü ekle')->assertSee('Teknik · Sayfa ayarları');

        // "AI ile geliştir" is offered for Geliştirilmeli only (here Teknik sorun until the page is indexable).
        $tab->call('improve', 'main', $row->id)->assertSee('yalnız "Geliştirilmeli"');
        $guide->forceFill(['is_indexable' => true])->save();
        $applied = [];
        ApplyChangeAgent::fake(function (string $prompt) use (&$applied): array {
            $applied[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['seo_title' => null, 'meta_description' => null, 'internal_links' => [], 'schema_json' => null, 'html' => null, 'note' => 'x'];
        });
        $tab->call('improve', 'main', $row->id);
        $this->assertSame(['Ağrı kaç gün sürer yanıtlanmamış'], $applied[0]['cluster']['gaps']);
        $this->assertSame(['Sayfa ayarları: Sayfa noindex — önce indekslemeyi açın', 'İlk günler başlığının altına: Ağrı kaç gün sürer bölümü ekle'], $applied[0]['cluster']['recipe']['steps']);

        // An extra idea without a page: Yeniden keşfet first, then AI ile üret writes the article with a link to the main page.
        BrandClusterPage::query()->whereKey($row->id)->update(['page_id' => $main->id]);
        $idea = ContentIdea::query()->create(['cluster_id' => $cluster->id, 'title' => 'İmplant sonrası beslenme rehberi', 'title_key' => 'implant sonrasi beslenme rehberi',
            'type' => 'guide', 'angle' => 'İlk haftalarda ne yenir.', 'target_queries' => [['text' => 'implant sonrası ne yenir', 'in_cluster' => true]], 'outline' => ['İlk gün', 'İlk hafta', 'Kaçınılacaklar']]);
        ClusterMatchAgent::fake(fn (string $prompt): array => ['clusters' => []]);
        ClusterGapsAgent::fake(fn (): array => ['clusters' => []]);
        $tab->call('rediscover', 'idea', $idea->id);
        $usage = BrandContentIdea::query()->sole();
        $this->assertSame([null, 'no_page'], [$usage->page_id, $usage->state]);
        $this->assertNotNull($usage->rediscovered_at);
        $written = [];
        WriteArticleAgent::fake(function (string $prompt) use (&$written): array {
            $written[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['title' => 'İmplant sonrası beslenme rehberi', 'slug' => 'implant-sonrasi-beslenme', 'meta_title' => 'Beslenme', 'meta_description' => 'Beslenme önerileri.',
                'excerpt' => 'Beslenme.', 'html' => '<h2>İlk gün</h2><p>Ilık ve yumuşak gıdalar.</p>'];
        });
        $tab->call('produce', 'extra', $usage->id);
        $this->assertSame(['İlk haftalarda ne yenir.', ['implant sonrası ne yenir'], 'https://panorama.com.tr/implant/', ['İlk gün', 'İlk hafta', 'Kaçınılacaklar']],
            [$written[0]['plan']['angle'], $written[0]['plan']['target_queries'], $written[0]['plan']['main_page_url'], $written[0]['plan']['outline']]);
        $article = data_get(Suggestion::query()->where('action_type', 'content')->sole()->action, 'article');
        $this->assertStringContainsString('<a href="https://panorama.com.tr/implant/">İmplant sonrası bakım</a>', $article['html'], 'an extra idea always links to the main page');
    }

    public function test_ai_ile_uret_sends_each_input_once_without_forbidden_phrases_and_keeps_the_recipe_seo_fields(): void
    {
        $this->enableAi();
        $main = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $cluster = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası bakım', 'implant sonrası ne yenir', 'en iyi implant sonrası diş macunu'], ['ağrı', 'beslenme'], 'informational');
        $cluster->forceFill(['page_type' => 'guide', 'ai_queries' => ['İmplant sonrası ağrı kaç gün sürer?']])->save();
        DB::table('queries')->where('text', 'implant sonrası bakım')->update(['impressions' => 10]);
        DB::table('queries')->where('text', 'implant sonrası ne yenir')->update(['impressions' => 90, 'hidden' => false]);
        DB::table('queries')->where('text', 'en iyi implant sonrası diş macunu')->update(['hidden' => true]);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $main->id, 'state' => 'weak_performance', 'language' => 'tr']);
        app(ForbiddenTermsLibrary::class)->saveBrand($this->brand, ['en iyi']);
        $idea = ContentIdea::query()->create(['cluster_id' => $cluster->id, 'title' => 'En iyi implant sonrası beslenme rehberi', 'title_key' => 'en iyi implant sonrasi beslenme rehberi',
            'type' => 'guide', 'angle' => 'İlk haftalarda en iyi ne yenir.', 'target_queries' => [['text' => 'implant sonrası en iyi yiyecekler', 'in_cluster' => false]],
            'outline' => ['İlk gün', 'İlk hafta', 'İlk hafta']]);
        $usage = BrandContentIdea::query()->create(['brand_id' => $this->brand->id, 'content_idea_id' => $idea->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page', 'language' => 'tr',
            'recipe' => ['steps' => [['order' => 1, 'area' => 'bolum', 'action' => 'En iyi yiyecekler tablosu ekle', 'where' => 'İlk hafta', 'why' => 'x', 'evidence' => []]],
                'seo_title' => 'İmplant Sonrası Beslenme | Panorama', 'meta_description' => 'İmplant sonrası ilk haftalarda ne yenir?']]);
        $sent = [];
        WriteArticleAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['title' => 'İmplant sonrası beslenme rehberi', 'slug' => 'x', 'meta_title' => 'Yazarın başlığı', 'meta_description' => 'Yazarın açıklaması.', 'excerpt' => 'x',
                'html' => '<p>İlk gün ılık gıdalar.</p>'];
        });

        $result = app(ContentPlanner::class)->produce(ContentIdeaSubject::find($this->site->id, 'extra', $usage->id));

        $this->assertSame('ready', $result['status']);
        $plan = $sent[0]['plan'];
        $this->assertSame(['İlk gün', 'İlk hafta'], $plan['outline'], 'a repeated heading goes once');
        $this->assertSame(['İmplant sonrası ağrı kaç gün sürer?'], $plan['questions']);
        $this->assertArrayNotHasKey('ai_questions', $sent[0]['cluster'], 'the plan questions are not sent twice');
        $this->assertSame(['implant sonrası yiyecekler'], $plan['target_queries']);
        $this->assertSame('İlk haftalarda ne yenir.', $plan['angle']);
        $this->assertArrayNotHasKey('reason', $plan, 'the reason repeats the angle');
        $this->assertSame(['İlk hafta: yiyecekler tablosu ekle'], $plan['recipe']);
        $this->assertSame(['new', 'İmplant Sonrası Beslenme | Panorama'], [$plan['kind'], $plan['seo_title']]);
        $this->assertStringNotContainsString('en-iyi', (string) $plan['target_url']);
        $this->assertSame(['implant sonrası ne yenir', 'implant sonrası bakım'], $sent[0]['cluster']['queries'], 'hidden queries left out, most impressions first');
        $article = data_get(Suggestion::query()->where('action_type', 'content')->sole()->action, 'article');
        $this->assertSame(['İmplant Sonrası Beslenme | Panorama', 'İmplant sonrası ilk haftalarda ne yenir?'], [$article['meta_title'], $article['meta_description']]);
    }
}
