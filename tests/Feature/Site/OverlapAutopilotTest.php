<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ApplyChangeAgent;
use App\Jobs\Site\PrepareOverlapRewriteJob;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\ClusterOverlapsList;
use App\Mcp\Tools\OverlapAct;
use App\Models\BrandClusterPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\OverlapAutopilot;
use Illuminate\Support\Facades\Queue;

/**
 * yakup 2026-10-14: "301 ve ayrıştır onarım masasındaki işleri yap. Claude API yapsın o dolarsa sen yap. Yapmadan önce
 * öneri doğru mu değil mi bir kere kontrol et." Claude reads the overlaps (MCP cluster-overlaps) and acts on them one
 * decision at a time; "Ayrıştır" is written by the AI in the background, by Claude when the API cannot.
 */
final class OverlapAutopilotTest extends SiteTestCase
{
    private Page $main;

    private Page $branch;

    private Suggestion $overlap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->main = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet']);
        $this->branch = $this->page('/karsiyaka-implant/', 'Karşıyaka İmplant', ['category' => 'lokasyon', 'content_text' => 'Karşıyaka şubemizde implant tedavisi. 2 hekim.']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'language' => 'tr', 'state' => 'possible_conflict', 'page_id' => $this->main->id, 'overlap_page_ids' => [$this->branch->id]]);
        app(ClusterOverlaps::class)->sync($this->site, $this->brand);
        $this->overlap = Suggestion::query()->where('decision_key', ClusterOverlaps::DECISION)->sole();
    }

    public function test_the_overlap_is_a_differentiate_and_the_mcp_list_shows_both_pages(): void
    {
        $this->assertSame([ClusterOverlaps::DIFFERENTIATE, 'location'], [data_get($this->overlap->action, 'recommendation'), data_get($this->overlap->action, 'basis')]);

        MoxdopServer::tool(ClusterOverlapsList::class, [])->assertOk()
            ->assertSee('/karsiyaka-implant/')->assertSee('/implant/')->assertSee('differentiate')->assertSee('Panorama Ankara');
        MoxdopServer::tool(ClusterOverlapsList::class, ['recommendation' => 'redirect'])->assertOk()->assertSee('"total":0');
    }

    public function test_ayristir_is_written_in_the_background_and_only_a_ready_version_is_sent(): void
    {
        Queue::fake([PrepareOverlapRewriteJob::class]);
        $autopilot = app(OverlapAutopilot::class);

        $this->assertSame([$this->overlap->id => 'Ayrıştırma yazımı başladı.'], $autopilot->act([$this->overlap->id], 'rewrite', $this->admin));
        Queue::assertPushed(PrepareOverlapRewriteJob::class, 1);
        $this->assertSame('preparing', data_get($this->overlap->fresh()->action, 'rewrite.state'));
        $this->assertStringContainsString('zaten sürüyor', $autopilot->act([$this->overlap->id], 'rewrite', $this->admin)[$this->overlap->id]);
        $this->assertStringContainsString('hazır sürüm yok', $autopilot->act([$this->overlap->id], 'publish', $this->admin)[$this->overlap->id]);
        $this->assertStringContainsString('301 önerilmiyor', $autopilot->act([$this->overlap->id], 'redirect', $this->admin)[$this->overlap->id]);
    }

    public function test_the_ai_refocuses_the_page_and_links_the_main_page(): void
    {
        $this->enableAi();
        $this->main->forceFill(['is_indexable' => false])->save();
        $this->overlap->forceFill(['action' => array_merge((array) $this->overlap->action, ['rewrite' => ['state' => 'preparing', 'by' => $this->admin->id]])])->save();
        ApplyChangeAgent::fake([[
            'seo_title' => 'Karşıyaka İmplant Tedavisi | Panorama', 'meta_description' => 'Karşıyaka şubemizde implant tedavisi.', 'html' => null,
            'internal_links' => [['anchor' => 'implant tedavisi', 'url' => 'https://panorama.com.tr/implant/']], 'schema_json' => null, 'note' => 'Şubeye odaklandı.',
        ]]);

        (new PrepareOverlapRewriteJob($this->overlap->id, $this->admin->id))->handle(app(OverlapAutopilot::class));

        $action = (array) $this->overlap->fresh()->action;
        $this->assertSame('ready', $action['rewrite']['state']);
        $this->assertSame('Karşıyaka İmplant Tedavisi | Panorama', $action['proposal']['new']['seo_title']);
        $this->assertSame('https://panorama.com.tr/implant/', $action['proposal']['new']['internal_links'][0]['url']);
        ApplyChangeAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'differentiate') && str_contains((string) $prompt->prompt, 'main_page'));
    }

    public function test_claude_writes_it_when_the_api_cannot_and_the_same_checks_apply(): void
    {
        $autopilot = app(OverlapAutopilot::class);

        $refused = $autopilot->act([$this->overlap->id], 'submit_rewrite', $this->admin, ['seo_title' => 'Karşıyaka İmplant: 12 hekim', 'note' => 'x']);
        $this->assertStringContainsString('doğrulanamadı', $refused[$this->overlap->id], 'a number that is not on the page');

        $done = $autopilot->act([$this->overlap->id], 'submit_rewrite', $this->admin, ['seo_title' => 'Karşıyaka İmplant Tedavisi', 'meta_description' => 'Karşıyaka şubemizde implant tedavisi.',
            'internal_links' => [['anchor' => 'implant tedavisi', 'url' => 'https://panorama.com.tr/implant/']], 'note' => 'Şubeye odaklandı.']);
        $this->assertStringContainsString('kontrollerden geçti', $done[$this->overlap->id]);
        $this->assertSame(['ready', 'claude_mcp'], [data_get($this->overlap->fresh()->action, 'rewrite.state'), data_get($this->overlap->fresh()->action, 'proposal.written_by')]);
    }

    public function test_mcp_overlap_act_decides_in_the_admins_name(): void
    {
        MoxdopServer::tool(OverlapAct::class, ['ids' => [$this->overlap->id], 'decision' => 'keep'])->assertOk()->assertSee('Ayrı kalsın olarak kapatıldı.');
        $this->assertSame([Suggestion::DISMISSED, $this->admin->id], [$this->overlap->fresh()->status, (int) $this->overlap->fresh()->resolved_by]);
        MoxdopServer::tool(OverlapAct::class, ['ids' => [1, 2], 'decision' => 'submit_rewrite'])->assertHasErrors();
        MoxdopServer::tool(OverlapAct::class, ['ids' => [1], 'decision' => 'delete'])->assertHasErrors();
    }

    public function test_keep_closes_a_wrong_proposal(): void
    {
        $result = app(OverlapAutopilot::class)->act([$this->overlap->id, 999999], 'keep', $this->admin);

        $this->assertSame('Ayrı kalsın olarak kapatıldı.', $result[$this->overlap->id]);
        $this->assertSame('Çakışma önerisi bulunamadı.', $result[999999]);
        $this->assertSame(Suggestion::DISMISSED, $this->overlap->fresh()->status);
        $this->assertStringContainsString('sonuçlandı', app(OverlapAutopilot::class)->act([$this->overlap->id], 'rewrite', $this->admin)[$this->overlap->id]);
    }
}
