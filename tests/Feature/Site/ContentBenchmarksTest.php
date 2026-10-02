<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ContentRecipeAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Site\ClusterBenchmarks;
use App\Services\Site\ContentIdeaSubject;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ContentRecipe;
use App\Services\Site\CopyCheck;
use App\Services\Website\Pages\PageStore;
use Illuminate\Support\Facades\DB;

/** Çıta sayfalar ve kopya kontrolü (docs/product/CONTENT_IDEAS_BLUEPRINT.md §6). */
final class ContentBenchmarksTest extends SiteTestCase
{
    public function test_benchmarks_are_other_brands_pages_scoring_60_or_more_best_three_and_only_their_skeleton(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası ağrı'], ['kanama', 'beslenme', 'sigara']);
        $own = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $this->page('/bakim/', 'Bakım')->id, 'state' => 'sufficient']);
        $this->score($own, 95);
        foreach ([['a', 81, 'scored'], ['b', 72, 'scored'], ['c', 65, 'scored'], ['d', 61, 'scored'], ['e', 59, 'scored'], ['f', null, 'low_data']] as [$key, $value, $state]) {
            [, $row] = $this->otherBrand($key, $cluster->id, [['level' => 2, 'text' => 'İlk 24 saat'], ['level' => 3, 'text' => 'Kanama'], ['level' => 2, 'text' => 'Ağrı ne kadar sürer?'], ['level' => 1, 'text' => 'Başlık']],
                'İlk gün kanama olabilir. Beslenme ılık olmalı. Gizli metin '.$key);
            $this->score($row, $value, $state);
        }

        $benchmarks = app(ClusterBenchmarks::class)->for($cluster, $this->brand->id);

        $this->assertSame([81, 72, 65], array_column($benchmarks, 'score'), 'other brands only, ≥ 60, scored, best three');
        $this->assertSame(['H2 İlk 24 saat', 'H3 Kanama', 'H2 Ağrı ne kadar sürer?'], $benchmarks[0]['outline']);
        $this->assertSame([1, ['kanama', 'beslenme']], [$benchmarks[0]['faq_count'], $benchmarks[0]['covered_subtopics']]);
        $this->assertStringNotContainsString('Gizli metin', json_encode($benchmarks, JSON_UNESCAPED_UNICODE), 'the text never goes to the AI');

        // The recipe pack carries them.
        $this->enableAi();
        $sent = [];
        ContentRecipeAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['summary' => 'Kısa.', 'steps' => [['order' => 1, 'area' => 'bolum', 'action' => 'Sigara bölümü ekle', 'where' => 'Sona', 'why' => 'Çıtada var', 'evidence' => []]],
                'seo_title' => null, 'meta_description' => null, 'expected_effect' => 'Yaklaşma.', 'measure_after_days' => 56];
        });
        app(ContentRecipe::class)->build(ContentIdeaSubject::find($this->site->id, 'main', $own->id));
        $this->assertSame([81, 72, 65], array_column($sent[0]['benchmarks'], 'score'));
    }

    public function test_copy_check_rejects_over_15_percent_shared_sequences_or_a_same_12_word_sentence(): void
    {
        $other = 'İmplant tedavisinden sonra ilk gün ılık ve yumuşak gıdalar tüketilmeli, sıcak içeceklerden kaçınılmalıdır. Diş fırçalama nazik yapılmalı.';
        $sentence = 'İmplant tedavisinden sonra ilk gün ılık ve yumuşak gıdalar tüketilmeli, sıcak içeceklerden kaçınılmalıdır.';
        $fresh = 'Kontrol randevusu tedaviden bir hafta sonra yapılır ve dikişler alınır. Ağrı genellikle birkaç gün içinde azalır, hekim önerisi dışında ilaç kullanılmamalıdır. '
            .'Sigara iyileşmeyi geciktirir; bu yüzden ilk günlerde bırakılması önerilir. Yumuşak gıdalar ve bol su tüketimi iyileşmeyi destekler.';

        $this->assertTrue(CopyCheck::check($fresh, ['https://x.test/a/' => $other])['ok']);
        $copied = CopyCheck::check($fresh.' '.$sentence, ['https://x.test/a/' => $other]);
        $this->assertFalse($copied['ok']);
        $this->assertSame([$sentence], $copied['sentences'], 'a same sentence of 12+ words');
        $this->assertStringContainsString('Kopya kontrolü: /a/ sayfasına çok benziyor', CopyCheck::message($copied));
        // Text the page already had does not count.
        $this->assertTrue(CopyCheck::check($fresh.' '.$sentence, ['https://x.test/a/' => $other], $sentence)['ok']);

        // Share: 5-word sequences, limit 15 %.
        $words = fn (int $from, int $count): string => implode(' ', array_map(fn (int $i): string => 'kelime'.$i, range($from, $from + $count - 1)));
        $source = $words(1, 40);
        $this->assertTrue(CopyCheck::check($words(1, 9).' '.$words(100, 41), ['u' => $source])['ok'], '5 of 46 sequences ≈ 11 %');
        $this->assertFalse(CopyCheck::check($words(1, 13).' '.$words(100, 37), ['u' => $source])['ok'], '9 of 46 sequences ≈ 20 %');
    }

    public function test_an_article_copying_another_brands_page_is_not_stored(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası ağrı']);
        $copied = 'İmplant tedavisinden sonra ilk gün ılık ve yumuşak gıdalar tüketilmeli, sıcak içeceklerden kaçınılmalıdır.';
        $this->otherBrand('z', $cluster->id, [], $copied.' Başka metin burada.');
        $suggestion = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => 'f1',
            'material_hash' => 'm', 'title' => 'İmplant sonrası beslenme', 'reason' => 'r', 'priority' => 2, 'evidence' => [], 'action_type' => 'content', 'target_type' => 'site',
            'target_id' => $this->site->id, 'cluster_id' => $cluster->id, 'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['Beslenme']]]);
        WriteArticleAgent::fake(fn (): array => ['title' => 'İmplant sonrası beslenme', 'slug' => 'beslenme', 'meta_title' => 'Beslenme', 'meta_description' => 'Beslenme.',
            'excerpt' => 'Beslenme.', 'html' => '<h2>Beslenme</h2><p>'.$copied.'</p><p>Bol su için.</p>']);

        $result = app(ContentPlanner::class)->writeArticle($suggestion);

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('Kopya kontrolü: /z/ sayfasına çok benziyor', $result['message']);
        $this->assertStringContainsString('aynı cümle', (string) data_get($suggestion->fresh()->action, 'article_blocked'));
        $this->assertNull(data_get($suggestion->fresh()->action, 'article'));
    }

    /** @return array{0: Brand, 1: BrandClusterPage} */
    private function otherBrand(string $key, int $clusterId, array $headings, string $text): array
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Marka '.$key, 'sector_id' => $this->dental->id]);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => $key.'.test', 'primary_url' => 'https://'.$key.'.test/']);
        $url = 'https://'.$key.'.test/'.$key.'/';
        $page = Page::query()->create(['website_asset_id' => $site->id, 'url' => $url, 'url_hash' => PageStore::urlHash($url), 'path' => '/'.$key.'/', 'title' => 'Sayfa '.$key,
            'headings' => $headings, 'content_text' => $text, 'content_hash' => hash('sha256', $key), 'word_count' => 1450, 'is_indexable' => true]);
        $row = BrandClusterPage::query()->create(['brand_id' => $brand->id, 'cluster_id' => $clusterId, 'website_asset_id' => $site->id, 'page_id' => $page->id, 'state' => 'sufficient']);

        return [$brand, $row];
    }

    private function score(BrandClusterPage $row, ?int $score, string $state = 'scored'): void
    {
        DB::table('cluster_page_scores')->insert(['brand_cluster_page_id' => $row->id, 'brand_id' => $row->brand_id, 'cluster_id' => $row->cluster_id, 'page_id' => $row->page_id,
            'state' => $state, 'score' => $score, 'impressions' => 500, 'clicks' => 30, 'position' => 3.4, 'covered_queries' => 3, 'cluster_queries' => 5,
            'coverage' => 0.6, 'ctr' => 0.06, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
