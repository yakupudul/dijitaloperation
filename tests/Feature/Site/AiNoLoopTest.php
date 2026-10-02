<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\QueryClusterAgent;
use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Ai\Agents\Site\ServicePagesAgent;
use App\Models\BrandClusterPage;
use App\Models\ClusterQuery;
use App\Models\OfferingPage;
use App\Models\Query;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Queries\QueryClusterer;
use App\Services\Queries\QueryNormalizer;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ServicePageMapper;
use App\Services\Site\SiteScope;

/**
 * AI work ends: a decision the AI made once is kept like a rule (never paid for again while its inputs stay the same),
 * and repeated decisions become rules (folder categories, cluster topics) so later work needs no AI at all.
 */
final class AiNoLoopTest extends SiteTestCase
{
    public function test_a_service_page_decision_is_asked_once_and_again_only_when_the_service_list_changes(): void
    {
        $this->enableAi();
        $smile = $this->page('/gulus-tasarimi/', 'Gülüş Tasarımı', ['category' => 'hizmet']);
        $other = $this->page('/estetik/', 'Estetik', ['category' => 'hizmet']);
        $calls = 0;
        ServicePagesAgent::fake(function () use (&$calls, $smile, $other): array {
            $calls++;

            return ['pages' => [['page_id' => $smile->id, 'service_id' => $this->zirkonyumOffering->id], ['page_id' => $other->id, 'service_id' => null]]];
        });

        app(ServicePageMapper::class)->map($this->site);
        app(ServicePageMapper::class)->map($this->site);

        $this->assertSame(1, $calls, 'the weekly refresh does not pay for the same pages again');
        $this->assertSame($this->zirkonyumOffering->id, (int) OfferingPage::query()->where('page_id', $smile->id)->value('brand_offering_id'));
        $this->assertTrue(OfferingPage::query()->where('page_id', $other->id)->whereNull('brand_offering_id')->where('source', 'ai')->exists(), '"no service" is remembered');

        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Gülüş Tasarımı');
        app(ServicePageMapper::class)->map($this->site);
        $this->assertSame(2, $calls, 'a changed service list: the pages without a rule match are judged once more');
        $this->assertNotSame($this->zirkonyumOffering->id, (int) OfferingPage::query()->where('page_id', $smile->id)->value('brand_offering_id'));
    }

    public function test_the_rule_pass_never_overwrites_an_ai_cluster_match(): void
    {
        $page = $this->page('/implant-rehberi/', 'İmplant rehberi', ['category' => 'blog']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        $row = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'language' => SiteScope::languages($this->site)[0] ?? null,
            'page_id' => $page->id, 'state' => 'sufficient', 'decided_by' => 'ai', 'coverage' => 'full', 'reason' => 'AI okudu.', 'audited_at' => now()]);

        app(ClusterPageMapper::class)->refresh($this->site, judge: false);

        $this->assertSame([$page->id, 'ai', 'sufficient', 'AI okudu.'], [$row->fresh()->page_id, $row->fresh()->decided_by, $row->fresh()->state, $row->fresh()->reason]);
    }

    public function test_a_folder_the_site_categorized_consistently_becomes_a_rule_but_never_for_service_pages(): void
    {
        $this->enableAi();
        foreach (range(1, PageCategorizer::LEARN_MIN) as $i) {
            $this->page('/ipuclari/yazi-'.$i.'/', 'Yazı '.$i, ['category' => 'blog', 'category_source' => 'ai']);
            $this->page('/paketler/paket-'.$i.'/', 'Paket '.$i, ['category' => 'hizmet', 'category_source' => 'ai']);
        }
        $new = $this->page('/ipuclari/yeni-yazi/', 'Yeni yazı');
        $newService = $this->page('/paketler/yeni-paket/', 'Yeni paket');
        $sent = [];
        PageCategoriesAgent::fake(function (string $prompt) use (&$sent): array {
            $sent[] = array_column(json_decode(substr($prompt, strlen("DATA_JSON\n")), true)['pages'], 'url');

            return ['pages' => []];
        });

        app(PageCategorizer::class)->categorize($this->site, onlyNew: true);

        $this->assertSame(['blog', 'rule'], [$new->fresh()->category, $new->fresh()->category_source], 'learned: no AI for this page');
        $this->assertSame([['https://panorama.com.tr/paketler/yeni-paket/']], $sent, '"hizmet" is never learned; that page still needs evidence');
        $this->assertNull($newService->fresh()->category);
    }

    public function test_a_new_query_of_a_topic_a_cluster_already_holds_joins_it_without_ai(): void
    {
        $this->enableAi();
        $cluster = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $new = Query::query()->create(['text' => 'implant fiyatı', 'text_hash' => QueryNormalizer::hash('implant fiyatı'), 'sector_id' => $this->dental->id,
            'service_id' => $this->implant->id, 'assignment' => 'rule', 'impressions' => 40]);
        Query::query()->whereIn('text', ['implant fiyatları', 'implant fiyatı'])->update(['topic_key' => 'implant fiyat']); // same rule-engine topic
        $calls = 0;
        QueryClusterAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;

            return ['clusters' => [], 'skipped' => []];
        });

        QueryClusterer::start($this->implant->id, 'place');
        $clusterer = app(QueryClusterer::class);
        do {
            $state = $clusterer->step($this->implant->fresh());
        } while (($state['status'] ?? null) === 'running');

        $this->assertSame(0, $calls);
        $this->assertTrue(ClusterQuery::query()->where('cluster_id', $cluster->id)->where('query_id', $new->id)->exists());
        $this->assertStringContainsString('1 sorgu kuralla yerleşti', QueryClusterer::label($state)['text']);
    }
}
