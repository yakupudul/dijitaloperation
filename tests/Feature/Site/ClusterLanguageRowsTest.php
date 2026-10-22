<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Models\BrandClusterPage;
use App\Models\Page;
use App\Services\Site\ClusterAudit;
use App\Services\Site\SiteScope;

/**
 * Avrupadent (2026-10-22): the Turkish pages carry no language field, so every English row of a service read them as
 * well and Claude got the Turkish question twice. An unmarked page is the site's main language; another language
 * reads only its own pages (or unmarked ones under its path).
 */
final class ClusterLanguageRowsTest extends SiteTestCase
{
    public function test_a_language_without_pages_of_its_own_asks_claude_nothing(): void
    {
        $this->enableAi();
        $this->page('/', 'Ana sayfa', ['language' => 'tr', 'category' => 'kurumsal']);
        $implant = $this->page('/implant/', 'İmplant Tedavisi', ['language' => null, 'category' => 'hizmet', 'content_text' => 'İmplant tedavisi adım adım anlatılır.']);
        $this->page('/en/veneers/', 'Veneers', ['language' => 'en', 'category' => 'hizmet', 'content_text' => 'Veneers are thin shells.']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        $cluster->forceFill(['approved' => true, 'ai_queries' => ['İmplant nasıl yapılır?']])->save();
        $calls = [];
        ClusterAiQueriesAgent::fake(fn (): array => ['clusters' => []]);
        ClusterMatchAgent::fake(function (string $prompt) use (&$calls, $cluster, $implant): array {
            $calls[] = json_decode(substr($prompt, strlen("DATA_JSON\n")), true);

            return ['clusters' => [['cluster_id' => $cluster->id, 'page_id' => $implant->id, 'coverage' => 'full', 'reason' => 'Sayfa tedaviyi anlatıyor.']]];
        });
        ClusterGapsAgent::fake(fn (): array => ['clusters' => [['cluster_id' => $cluster->id, 'coverage' => 'full', 'gaps' => []]]]);

        app(ClusterAudit::class)->run($this->site);

        $this->assertCount(1, $calls, 'only the Turkish row is asked');
        $this->assertSame(['https://panorama.com.tr/implant/'], array_column($calls[0]['pages'], 'url'));
        $rows = BrandClusterPage::query()->where('cluster_id', $cluster->id)->get()->keyBy('language');
        $this->assertSame($implant->id, $rows['tr']->page_id);
        $this->assertNull($rows['en']->page_id, 'a Turkish page is never the English row\'s page');
    }

    public function test_an_unmarked_page_belongs_to_the_main_language_or_to_the_language_of_its_path(): void
    {
        $page = fn (string $path, ?string $language): Page => new Page(['path' => $path, 'language' => $language]);

        $this->assertTrue(SiteScope::inLanguage($page('/implant/', null), 'tr', 'tr'));
        $this->assertFalse(SiteScope::inLanguage($page('/implant/', null), 'en', 'tr'));
        $this->assertTrue(SiteScope::inLanguage($page('/en/implant/', null), 'en', 'tr'));
        $this->assertFalse(SiteScope::inLanguage($page('/en/implant/', 'en'), 'tr', 'tr'));
        $this->assertTrue(SiteScope::inLanguage($page('/x/', 'de'), null, 'tr'));
    }
}
