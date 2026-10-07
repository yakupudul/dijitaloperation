<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\WriteArticleAgent;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\ComplianceRule;
use App\Models\PromptVersion;
use App\Models\Suggestion;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Compliance\ForbiddenTermsLibrary;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\Prompts\PromptRegistry;
use App\Services\Site\ArticleSeoCheck;
use App\Services\Site\ContentPlanner;
use Livewire\Livewire;

/** Yazım kuralları (yakup, 2026-10-02): "{marka}" sector rule, the after-writing SEO check, the final prompt rollout. */
final class ArticleRulesTest extends SiteTestCase
{
    public function test_a_brand_token_rule_forbids_each_brands_own_name_in_ai_content_only(): void
    {
        $rule = app(ForbiddenTermsLibrary::class)->add($this->dental, '{marka}', 'İçerikte marka adı geçmez.', 'high');

        $this->assertSame(['ai_draft', 'seo_brief'], $rule->applies_to, 'the brand\'s own pages, profile and ads are not audited for its name');
        $this->assertSame(['{marka}'], $rule->fresh()->patterns, 'stored as written; filled per brand');
        $terms = ForbiddenTerms::forBrand($this->brand);
        $this->assertSame(['Panorama Ankara', 'panorama'], array_slice($terms->phrases(), -2), 'brand name and domain root');
        $this->assertSame(['panorama'], $terms->blocking('Panorama kliniğinde implant'));
        $this->assertSame('İmplant rehberi', $terms->scrub('Panorama İmplant rehberi'), 'writer inputs lose the name');
        $this->assertNotContains('{marka}', ForbiddenTerms::forSector($this->dental->id)->phrases(), 'without a brand the token stands for nothing');

        $this->site->forceFill(['domain' => 'implant.com.tr'])->save();
        $this->assertSame(['Panorama Ankara'], array_slice(ForbiddenTerms::forBrand($this->brand)->phrases(), -1), 'a service word domain is not the brand');
        $this->assertNotContains('implant', ForbiddenTerms::forBrand($this->brand)->phrases());
        $this->site->forceFill(['domain' => 'panorama.com.tr'])->save();

        $article = ArticleDraft::fromArray(['title' => 'Panorama Ankara ile implant', 'slug' => 'implant', 'html' => '<p>Metin.</p>',
            'meta_title' => 'İmplant', 'meta_description' => 'İmplant.', 'excerpt' => '', 'language' => 'tr', 'post_type' => 'post', 'reference' => 't']);
        $this->assertNotSame([], ContentComplianceGate::blocking(app(ContentComplianceGate::class)->violations($this->brand, $article)));
        $this->assertSame(['{marka}'], ComplianceRule::query()->findOrFail($rule->id)->patterns, 'reading never rewrites the rule');
    }

    public function test_the_seo_check_names_what_the_article_misses(): void
    {
        $good = ['title' => 'Çankaya İmplant Tedavisi: Süreç ve Bakım', 'html' => '<p>İmplant tedavisi Çankaya şubemizde planlanır.</p><h2>Süreç</h2><p>Ayrıntılar <a href="https://panorama.com.tr/implant/">implant</a> sayfasında.</p>'];
        $this->assertSame([], ArticleSeoCheck::check($good, 'implant tedavisi', ['Çankaya'], 'https://panorama.com.tr'));

        $bad = ['title' => 'Diş sağlığı hakkında', 'html' => '<p>Genel bilgi.</p>'.str_repeat('<p>implant tedavisi implant tedavisi</p>', 3)];
        $issues = ArticleSeoCheck::check($bad, 'implant tedavisi', ['Çankaya'], 'https://panorama.com.tr');
        $this->assertSame([
            'Ana sorgu «implant tedavisi» başlıkta geçmiyor.',
            'Ana sorgu «implant tedavisi» ilk paragrafta geçmiyor.',
            'Ana sorgu birebir 6 kez tekrar ediyor (14 kelime); doğal varyasyonlarla azaltın.',
            'Sitenin kendi sayfalarına iç bağlantı yok.',
            'Hizmet bölgelerinden hiçbiri (Çankaya) metinde geçmiyor.',
        ], $issues);
        $this->assertTrue(ArticleSeoCheck::coversQuery('İmplantın faydaları', 'implant'), 'Turkish suffixes count');
    }

    public function test_the_written_article_carries_its_seo_check_and_the_reader_shows_it(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nasıl yapılır'], ['Süreç']);
        $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $idea = Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5('seo'), 'material_hash' => md5('seo'),
            'title' => 'İmplant tedavisi nasıl yapılır', 'reason' => 'x', 'priority' => 2, 'evidence' => [], 'action_type' => 'content', 'cluster_id' => $cluster->id,
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['Giriş'], 'questions' => []],
            'status' => Suggestion::APPROVED, 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        WriteArticleAgent::fake([['title' => 'İmplant tedavisi nasıl yapılır', 'slug' => 'implant', 'meta_title' => 'İmplant tedavisi', 'meta_description' => 'Süreç.',
            'excerpt' => 'Rehber.', 'html' => '<p>Bu yazı tedavinin adımlarını anlatır.</p><h2>Süreç</h2><p>Adım adım.</p>']]);

        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($idea->fresh()));

        $seo = $idea->fresh()->action['article_seo'];
        $this->assertContains('Ana sorgu «implant tedavisi» ilk paragrafta geçmiyor.', $seo);
        $this->assertContains('Sitenin kendi sayfalarına iç bağlantı yok.', $seo);
        Livewire::test(WorkPage::class)->call('read', $idea->id)->assertSeeHtml('data-article-seo')->assertSee('iç bağlantı yok');
    }

    public function test_the_new_writer_prompt_reaches_an_operation_handed_to_claude_and_keeps_its_model(): void
    {
        $registry = app(PromptRegistry::class);
        $registry->publish('site.write_article', ['template' => 'Eski yazım talimatı.', 'model' => AiTaskQueue::MODEL], $this->admin);
        $this->assertStringContainsString('Eski yazım talimatı.', $registry->current('site.write_article')->template, 'a published prompt does not follow the code');

        $this->artisan('moxdop:prompts:adopt-default', ['operations' => ['site.write_article', 'yok.boyle']])
            ->expectsOutputToContain('kod varsayılanı yayında')->expectsOutputToContain('bilinmeyen işlem')->assertSuccessful();

        $current = $registry->current('site.write_article');
        $this->assertStringContainsString('site-write-article-v10', $current->template);
        $this->assertSame(AiTaskQueue::MODEL, $current->model, 'Claude (MCP) stays');
        $this->assertSame($this->admin->id, $current->created_by);
        $this->artisan('moxdop:prompts:adopt-default', ['operations' => ['site.write_article']])->expectsOutputToContain('zaten')->assertSuccessful();
        $this->assertSame(1, PromptVersion::query()->where('operation', 'site.write_article')->where('is_current', true)->count());
    }
}
