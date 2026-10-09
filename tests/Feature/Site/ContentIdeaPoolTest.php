<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ContentIdeasAgent;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\Brand;
use App\Models\BrandContentIdea;
use App\Models\ContentIdea;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Site\ContentIdeaPool;
use Livewire\Livewire;

/** İçerik havuzu and "Yeni fikir üret" (docs/product/CONTENT_IDEAS_BLUEPRINT.md §4). */
final class ContentIdeaPoolTest extends SiteTestCase
{
    public function test_new_ideas_from_sorgular_are_checked_and_land_in_the_system_wide_pool_without_brand_context(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi süreci', ['implant tedavisi', 'implant sonrası ne yenir', 'implant mı köprü mü']);
        $sent = [];
        ContentIdeasAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['ideas' => [
                ['title' => 'İmplant sonrası beslenme rehberi', 'type' => 'guide', 'angle' => 'Tedaviden sonraki ilk haftalar.',
                    'target_queries' => ['İmplant sonrası ne yenir', 'implant sonrası çorba'], 'outline' => ['İlk 24 saat', 'İlk hafta', 'Kaçınılacaklar', 'İmplant tedavisi']],
                ['title' => 'İmplant mı köprü mü karşılaştırması', 'type' => 'comparison', 'angle' => 'İki seçeneğin farkı.',
                    'target_queries' => ['implant mı köprü mü'], 'outline' => ['Ömür', 'Maliyet', 'Kemik', 'Hangisi kime']],
                ['title' => 'İmplant Tedavisi Süreci', 'type' => 'service', 'angle' => 'Kümenin kendisi.', 'target_queries' => ['implant tedavisi'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'Diş beyazlatma hakkında her şey', 'type' => 'guide', 'angle' => 'Başka konu.', 'target_queries' => ['diş beyazlatma'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'İmplant bakım ipuçları listesi', 'type' => 'blog', 'angle' => 'Geçersiz tür.', 'target_queries' => ['implant tedavisi'], 'outline' => ['A', 'B', 'C']],
            ]];
        });

        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->set('service', (string) $this->implant->id)->call('openCluster', $cluster->id)
            ->assertSee('Henüz ek fikir yok.')
            ->set('ideaCount', '5')->call('generateIdeas')
            ->assertSee('2 fikir havuza eklendi.')
            ->assertSee('İmplant sonrası beslenme rehberi')->assertSee('karşılaştırma')->assertSee('implant sonrası çorba (önerilen)')
            ->assertSee('Sorgular (genel)')
            ->assertSee('Kümenin kendisiyle ya da havuzdaki bir fikirle aynı.')
            ->assertSee('Hedef sorgularından hiçbiri kümenin sorgusu değil.')
            ->assertSee('Geçersiz tür.');

        $this->assertCount(1, $sent);
        $this->assertArrayNotHasKey('brand', $sent[0]);
        $this->assertArrayNotHasKey('site_pages', $sent[0]);
        $this->assertSame(5, $sent[0]['count']);
        $this->assertSame('İmplant tedavisi süreci', $sent[0]['cluster']['name']);
        $this->assertSame(2, ContentIdea::query()->count());
        $idea = ContentIdea::query()->where('type', 'guide')->firstOrFail();
        $this->assertNull($idea->origin_brand_id);
        $this->assertSame($this->admin->id, $idea->created_by);
        $this->assertSame([['text' => 'İmplant sonrası ne yenir', 'in_cluster' => true], ['text' => 'implant sonrası çorba', 'in_cluster' => false]], $idea->target_queries);
    }

    public function test_a_brand_screen_sends_brand_context_and_the_pool_is_shared_with_its_users(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant sonrası ne yenir', 'implant kliniği seçimi']);
        $this->page('/blog/implant-bakimi/', 'İmplant bakımı', ['category' => 'blog']);
        $this->page('/blog/kahve-lekesi/', 'Kahve lekesi nasıl çıkar', ['category' => 'blog']);
        $existing = ContentIdea::query()->create(['cluster_id' => $cluster->id, 'title' => 'İmplant sonrası beslenme rehberi', 'title_key' => 'implant sonrasi beslenme rehberi',
            'type' => 'guide', 'target_queries' => [['text' => 'implant sonrası ne yenir', 'in_cluster' => true]], 'outline' => ['A', 'B', 'C']]);
        $sent = [];
        ContentIdeasAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['ideas' => [
                ['title' => 'İmplant Sonrası Beslenme Rehberi!', 'type' => 'guide', 'angle' => 'Tekrar.', 'target_queries' => ['implant sonrası ne yenir'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'Çankaya implant kliniği seçimi', 'type' => 'location', 'angle' => 'Bölgedeki hastalar için.', 'target_queries' => ['implant kliniği seçimi', 'implant tedavisi'], 'outline' => ['A', 'B', 'C', 'D', 'E']],
            ]];
        });

        $result = app(ContentIdeaPool::class)->generate($cluster, $this->brand, 2, $this->admin);

        $this->assertSame(1, $result['added']);
        $this->assertSame('Kümenin kendisiyle ya da havuzdaki bir fikirle aynı.', $result['rejected'][0]['reason']);
        $this->assertSame('Panorama Ankara', $sent[0]['brand']['name']);
        $this->assertContains('Çankaya', $sent[0]['brand']['areas']);
        $this->assertSame(['https://panorama.com.tr/blog/implant-bakimi/'], array_column($sent[0]['site_pages'], 'url'), 'only the pages about the cluster');
        $this->assertSame('tr', $sent[0]['brand']['language']);
        $this->assertSame([['title' => 'İmplant sonrası beslenme rehberi', 'type' => 'guide']], $sent[0]['existing_ideas']);
        $this->assertSame($this->brand->id, ContentIdea::query()->where('type', 'location')->value('origin_brand_id'));

        // Another brand sees the same pool; brands with a page for an idea are its users.
        $other = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Klinik İzmir', 'sector_id' => $this->dental->id]);
        $otherSite = DigitalAsset::factory()->create(['brand_id' => $other->id, 'type' => 'website', 'domain' => 'klinik.test', 'primary_url' => 'https://klinik.test/']);
        BrandContentIdea::query()->create(['brand_id' => $other->id, 'content_idea_id' => $existing->id, 'website_asset_id' => $otherSite->id,
            'page_id' => $this->page('/beslenme/', 'Beslenme')->id, 'state' => 'sufficient']);
        BrandContentIdea::query()->create(['brand_id' => $this->brand->id, 'content_idea_id' => $existing->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page']);

        Livewire::test(QueriesPage::class)->call('setTab', 'clusters')->set('service', (string) $this->implant->id)->call('openCluster', $cluster->id)
            ->assertSee('Çankaya implant kliniği seçimi')->assertSee('Üreten: Panorama Ankara')
            ->assertSee('Kullanan markalar: Klinik İzmir')
            ->call('archiveIdea', $existing->id)->assertDontSee('İmplant sonrası beslenme rehberi');
        $this->assertSame('archived', $existing->fresh()->status);
    }

    public function test_an_extra_idea_must_target_its_own_cluster_query_and_is_planned_in_the_sites_main_language(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant sonrası ne yenir', 'implant sonrası ağrı', 'implant ömrü']);
        $this->page('/en/dental-implants/', 'Dental implants', ['category' => 'hizmet', 'language' => 'en']);
        $this->page('/implant-tedavisi/', 'İmplant tedavisi', ['category' => 'hizmet', 'language' => 'de']);
        $this->page('/de/implantat/', 'Implantat', ['category' => 'hizmet', 'language' => 'de']);
        ContentIdea::query()->create(['cluster_id' => $cluster->id, 'title' => 'İmplant sonrası beslenme', 'title_key' => 'implant sonrasi beslenme',
            'type' => 'guide', 'target_queries' => [['text' => 'implant sonrası ne yenir', 'in_cluster' => true]], 'outline' => ['A', 'B', 'C']]);
        $sent = [];
        ContentIdeasAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['ideas' => [
                ['title' => 'İmplant tedavisi kimlere uygun', 'type' => 'guide', 'angle' => 'Ana sorgu.', 'target_queries' => ['implant tedavisi', 'implant ömrü'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'İmplant sonrası yumuşak yiyecekler', 'type' => 'guide', 'angle' => 'Aynı hedef.', 'target_queries' => ['implant sonrası ne yenir'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'Diş eti çekilmesi ve implant', 'type' => 'guide', 'angle' => 'İlk hedef kümede değil.', 'target_queries' => ['diş eti çekilmesi', 'implant ömrü'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'İmplant sonrası ağrı ne kadar sürer', 'type' => 'faq', 'angle' => 'Kendi sorgusu.', 'target_queries' => ['implant sonrası ağrı'], 'outline' => ['A', 'B', 'C']],
                ['title' => 'İmplant sonrası ağrı kesiciler', 'type' => 'guide', 'angle' => 'Aynı turdaki fikrin hedefi.', 'target_queries' => ['implant sonrası ağrı'], 'outline' => ['A', 'B', 'C']],
            ]];
        });

        $result = app(ContentIdeaPool::class)->generate($cluster, $this->brand, 5, $this->admin);

        $this->assertSame(1, $result['added']);
        $this->assertSame([
            'İlk hedef sorgusu kümenin ana sorgusu; ek fikir kendi sorgusunu hedeflemeli.',
            'İlk hedef sorgusu havuzdaki başka bir fikrin hedefi.',
            'İlk hedef sorgusu kümenin bir sorgusu olmalı.',
            'İlk hedef sorgusu havuzdaki başka bir fikrin hedefi.',
        ], array_column($result['rejected'], 'reason'));
        $this->assertSame('de', $sent[0]['brand']['language'], 'the main language of the site, never a hardcoded one');
        $this->assertSame(['https://panorama.com.tr/implant-tedavisi/'], array_column($sent[0]['site_pages'], 'url'),
            'pages of the main language about the cluster; the English page and an unrelated page stay out');
    }
}
