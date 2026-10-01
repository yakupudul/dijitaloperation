<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ContentIdeasAgent;
use App\Ai\Agents\Site\ForbiddenTermsAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Livewire\Operator\Library\QueriesPage;
use App\Livewire\Operator\Portfolio\BrandSettings;
use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Models\ContentIdea;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Compliance\ForbiddenTermsLibrary;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\Site\ContentIdeaPool;
use App\Services\Site\ContentPlanner;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Yasaklı ifadeler (docs/product/CONTENT_IDEAS_BLUEPRINT.md §7): one source, four enforcement points, brand-only rules. */
final class ForbiddenTermsTest extends SiteTestCase
{
    public function test_the_tab_adds_switches_deletes_and_approves_ai_suggestions_one_by_one(): void
    {
        $this->enableAi();
        $page = Livewire::test(QueriesPage::class)->call('setTab', 'forbidden')->assertSee('Bir sektör seçin')
            ->set('sector', (string) $this->dental->id)
            ->assertSee('sektör paketi', false)
            ->set('forbiddenPhrase', 'Şimşek Tedavi')->set('forbiddenReason', 'Yanıltıcı vaat.')->set('forbiddenSeverity', 'high')->call('addForbidden')
            ->assertSee('şimşek tedavi')->assertSee('Yanıltıcı vaat.');
        $rule = ComplianceRule::query()->where('pack_id', 'sector:dental')->sole();
        $this->assertSame([['şimşek tedavi'], 'high', 'operator'], [$rule->patterns, $rule->severity, $rule->origin]);
        $page->set('forbiddenPhrase', 'şimşek tedavi')->call('addForbidden')->assertHasErrors('forbiddenPhrase');

        $sent = [];
        ForbiddenTermsAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['terms' => [['phrase' => 'İnanılmaz Sonuç', 'reason' => 'Ağrısızlık vaadi.', 'severity' => 'block'],
                ['phrase' => 'şimşek tedavi', 'reason' => 'Tekrar.', 'severity' => 'block'],
                ['phrase' => 'yıldız hekim', 'reason' => 'Fiyat iddiası.', 'severity' => 'warn']]];
        });
        $page->call('suggestForbidden')->assertSee('inanılmaz sonuç')->assertSee('yıldız hekim')->assertDontSee('Tekrar.');
        $this->assertSame('Diş sağlığı', $sent[0]['sector']);
        $this->assertContains('şimşek tedavi', $sent[0]['existing']);
        $page->call('decideForbidden', 0, true)->call('decideForbidden', 0, false)->assertDontSee('yıldız hekim');
        $ai = ComplianceRule::query()->where('pack_id', 'sector:dental')->where('origin', 'ai')->sole();
        $this->assertSame(['inanılmaz sonuç'], $ai->patterns);

        $page->call('toggleForbidden', $rule->id);
        $this->assertFalse($rule->fresh()->active);
        $page->call('deleteForbidden', $rule->id);
        $this->assertNull($rule->fresh());
    }

    public function test_four_enforcement_points_and_brand_rules_apply_only_to_their_brand(): void
    {
        $this->enableAi();
        $library = app(ForbiddenTermsLibrary::class);
        $library->add($this->dental, 'şimşek tedavi', 'Yanıltıcı vaat.', 'high');
        $library->add($this->dental, 'yıldız hekim', 'Fiyat iddiası.', 'low');
        Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])->set('forbidden', "Panorama Farkı\nmavi klinik")->call('saveForbidden');
        $this->assertSame(['panorama farkı', 'mavi klinik'], ComplianceRule::query()->where('pack_id', 'brand:'.$this->brand->id)->sole()->patterns);

        // Brand rules only for their brand; sector rules for every brand of the sector.
        $other = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Diğer', 'sector_id' => $this->dental->id]);
        $this->assertSame(['panorama farkı'], ForbiddenTerms::forBrand($this->brand)->blocking('Panorama farkı ile'));
        $this->assertSame([], ForbiddenTerms::forBrand($other)->blocking('Panorama farkı ile'));
        $this->assertSame(['şimşek tedavi'], ForbiddenTerms::forBrand($other)->blocking('Bu bir şimşek tedavi.'));

        // 1 + 4: the idea pack carries the sector's phrases (no brand from Sorgular) and an idea using one is not stored.
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi süreci', ['implant tedavisi', 'implant nasıl yapılır']);
        $sent = [];
        ContentIdeasAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['ideas' => [
                ['title' => 'İmplantta şimşek tedavi rehberi', 'type' => 'guide', 'angle' => 'x', 'target_queries' => ['implant tedavisi'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'İmplant nasıl yapılır rehberi', 'type' => 'guide', 'angle' => 'Adım adım.', 'target_queries' => ['implant nasıl yapılır'], 'outline' => ['A', 'B', 'C']],
            ]];
        });
        $result = app(ContentIdeaPool::class)->generate($cluster, null, 2);
        $this->assertContains('şimşek tedavi', $sent[0]['forbidden']);
        $this->assertNotContains('panorama farkı', $sent[0]['forbidden'], 'no brand context from Sorgular');
        $this->assertSame([1, 'Yasaklı ifade içeriyor: «şimşek tedavi».'], [$result['added'], $result['rejected'][0]['reason']]);
        $this->assertSame(['İmplant nasıl yapılır rehberi'], ContentIdea::query()->pluck('title')->all());

        // 2: before storing — "engelle" is not stored, "uyar" is marked.
        $suggestion = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => 'f1',
            'material_hash' => 'm', 'title' => 'İmplant rehberi', 'reason' => 'r', 'priority' => 2, 'evidence' => [], 'action_type' => 'content', 'target_type' => 'site',
            'target_id' => $this->site->id, 'cluster_id' => $cluster->id, 'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['A']]]);
        $html = '<h2>Süreç</h2><p>İmplant adım adım yapılır.</p>';
        WriteArticleAgent::fake(function (string $prompt) use (&$html, &$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['title' => 'İmplant rehberi', 'slug' => 'implant', 'meta_title' => 'İmplant', 'meta_description' => 'İmplant.', 'excerpt' => 'İmplant.', 'html' => $html];
        });
        $html = '<p>Panorama farkı ile implant yapılır.</p>';
        $this->assertSame('blocked', app(ContentPlanner::class)->writeArticle($suggestion)['status']);
        $this->assertContains('panorama farkı', end($sent)['forbidden'], 'brand phrases in the article pack');
        $html = '<p>Yıldız hekim kadromuz için bize ulaşın; implant adım adım yapılır.</p>';
        $this->assertSame('ready', app(ContentPlanner::class)->writeArticle($suggestion)['status']);
        $this->assertSame('Uyarı (yasaklı ifade, uyar): «yıldız hekim»', data_get($suggestion->fresh()->action, 'article_warnings'));

        // 3: the WordPress gate reads the same rules.
        $gate = app(ContentComplianceGate::class);
        $this->expectException(ValidationException::class);
        $gate->assertCompliant($this->brand, ArticleDraft::fromArray(['title' => 'Mavi klinik', 'html' => '<p>Metin.</p>', 'reference' => 'x']));
    }
}
