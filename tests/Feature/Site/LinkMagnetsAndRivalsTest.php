<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Links\LinkDeskPage;
use App\Models\BacklinkSource;
use App\Models\LinkSource;
use App\Models\LinkTarget;
use App\Models\Suggestion;
use App\Services\Site\Backlinks\LinkDesk;
use App\Services\Site\Backlinks\LinkMagnets;
use App\Services\Site\Backlinks\LinkRivals;
use App\Services\Site\Backlinks\LinkTargets;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Backlink hizmeti, adım 4 (2026-11-06): one link magnet per brand goes to İçerik as a title for approval and, once live,
 * becomes the desk's target for news and associations; pool sources that link to the brand's competitors and not to
 * the brand come first (rakip boşluğu from the tracked Google results and the pages the quality gate read).
 */
final class LinkMagnetsAndRivalsTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_the_magnet_goes_to_content_once_and_becomes_a_lasting_target_when_live(): void
    {
        $magnet = LinkMagnets::forBrand($this->brand);
        $this->assertNotNull($magnet);
        $this->assertNull(ContentPlanner::styleProblem($magnet['title']), 'the title survives the content pool\'s style rules');

        Livewire::test(LinkDeskPage::class)->set('tab', 'hedefler')->assertSee('Link mıknatısı')->call('proposeMagnet', $this->brand->id)->assertSee('İçerik\'e eklendi');
        $idea = Suggestion::query()->where('brand_id', $this->brand->id)->sole();
        $this->assertSame([SiteSuggestionTypes::CONTENT, Suggestion::OPEN, $magnet['title'], LinkMagnets::SOURCE], [$idea->action_type, $idea->status, $idea->title, $idea->action['source']]);
        $this->assertSame('Mıknatıs konusu zaten İçerik\'te.', app(LinkMagnets::class)->propose($this->brand));
        $this->assertNull(LinkMagnets::ensureTarget($this->brand), 'not live yet');

        $idea->forceFill(['status' => Suggestion::APPLIED])->save();
        $this->page((string) parse_url($idea->action['target_url'], PHP_URL_PATH), $magnet['title']);
        $target = LinkMagnets::ensureTarget($this->brand);
        $this->assertSame([LinkMagnets::TARGET_KIND, LinkTarget::ACTIVE], [$target->kind, $target->status]);

        // The page targets are refreshed around it; months later it is still a target.
        $this->travel(120)->days();
        app(LinkTargets::class)->refresh($this->brand);
        $this->assertSame(LinkTarget::ACTIVE, $target->fresh()->status);

        // A news site is asked to point to the magnet.
        LinkSource::query()->create(['domain' => 'haber.example', 'name' => 'Ankara Haber', 'url' => 'https://haber.example/', 'kind' => 'yerel_haber', 'quality' => 'uygun', 'city' => 'ankara']);
        app(LinkDesk::class)->fill($this->brand->fresh());
        $row = BacklinkSource::query()->where('domain', 'haber.example')->sole();
        $this->assertSame([$target->id, 'Panorama Ankara'], [$row->link_target_id, $row->anchor]);
    }

    public function test_a_source_that_links_to_competitors_and_not_to_us_comes_first(): void
    {
        $cluster = $this->cluster($this->implant, 'Ankara implant', ['ankara implant']);
        DB::table('brand_cluster_serps')->insert(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'query' => 'ankara implant',
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(), 'results' => json_encode([
                ['rank' => 1, 'url' => 'https://www.rakipdis.example/implant', 'domain' => 'www.rakipdis.example', 'class' => 'ticari'],
                ['rank' => 2, 'url' => 'https://panorama.com.tr/implant/', 'domain' => 'panorama.com.tr', 'class' => 'ticari'],
                ['rank' => 3, 'url' => 'https://vikipedi.example/implant', 'domain' => 'vikipedi.example', 'class' => 'bilgi'],
            ])]);
        $this->assertSame(['rakipdis.example' => 1], LinkRivals::rivals($this->brand));

        $pool = fn (string $domain, array $extra): LinkSource => LinkSource::query()->create(array_merge(['domain' => $domain, 'name' => $domain, 'url' => 'https://'.$domain.'/',
            'kind' => 'rehber', 'city' => 'ankara', 'quality' => 'uygun'], $extra));
        $pool('iyi.example', ['won_count' => 5, 'applied_count' => 5]);
        $pool('rehber.example', ['linked_domains' => ['rakipdis.example', 'baska.example']]);
        $pool('bizdede.example', ['linked_domains' => ['rakipdis.example', 'panorama.com.tr']]);
        $this->brand->forceFill(['link_package' => 'otorite'])->save();

        app(LinkDesk::class)->fill($this->brand->fresh());

        $first = BacklinkSource::query()->where('brand_id', $this->brand->id)->orderBy('id')->first();
        $this->assertSame(['rehber.example', 'rakip'], [$first->domain, $first->engine]);
        $this->assertStringStartsWith('Rakiplerinize link veriyor: rakipdis.example', $first->reason);
        $this->assertSame('kunye', BacklinkSource::query()->where('domain', 'bizdede.example')->value('engine'), 'a source that already links to us is no gap');
    }
}
