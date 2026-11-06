<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\ProposeBacklinkSourcesJob;
use App\Jobs\Site\WriteLinkPack;
use App\Livewire\Operator\Links\LinkDeskPage;
use App\Models\BacklinkSource;
use App\Models\BrandEpicrisisItem;
use App\Models\LinkSource;
use App\Models\LinkSurvey;
use App\Services\Site\Backlinks\LinkCitations;
use App\Services\Site\Backlinks\LinkDesk;
use App\Services\Site\Backlinks\LinkSourceGate;
use App\Services\Website\PageFetcher;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

/**
 * Backlink hizmeti, adım 2 (2026-11-06): no pool source reaches a brand before the quality gate (link sellers and link
 * farms are refused by rules, Claude judges the rest), the national listings and a monthly discovery keep the pool
 * fed, and the client's "Bağlantı kaynakları" form turns real relations into epikriz facts and desk rows.
 */
final class LinkGateAndSurveyTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_the_gate_refuses_link_sellers_and_link_farms_by_rules_and_applies_claudes_verdicts(): void
    {
        $seller = LinkSource::query()->create(['domain' => 'satici.example', 'name' => 'Satıcı', 'url' => 'https://satici.example/', 'kind' => 'dizin']);
        $farm = LinkSource::query()->create(['domain' => 'ciftlik.example', 'name' => 'Çiftlik', 'url' => 'https://ciftlik.example/', 'kind' => 'dizin']);
        $guide = LinkSource::query()->create(['domain' => 'rehber.example', 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'kind' => 'dizin']);
        $closed = LinkSource::query()->create(['domain' => 'kapali.example', 'name' => 'Kapalı', 'url' => 'https://kapali.example/', 'kind' => 'dizin']);
        $row = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Satıcı', 'url' => 'https://satici.example/', 'domain' => 'satici.example',
            'status' => 'yok', 'link_source_id' => $seller->id]);
        $links = implode('', array_map(fn (int $i): string => '<a href="https://site'.$i.'.example/">x</a>', range(1, 160)));
        $fetcher = Mockery::mock(PageFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturnUsing(fn (string $url): array => ['html' => match (true) {
            str_contains($url, 'satici') => "<title>Satıcı</title>\n<p>Tanıtım yazısı ve dofollow backlink paketleri</p>",
            str_contains($url, 'ciftlik') => '<title>Çiftlik</title>'.$links,
            str_contains($url, 'rehber') => '<title>Ankara Firma Rehberi</title><meta name="description" content="Ankara işletmeleri"><p>Firmanızı ekleyin</p>',
            default => '',
        }]);
        $this->app->instance(PageFetcher::class, $fetcher);
        $gate = app(LinkSourceGate::class);

        $out = $gate->check([$seller->id, $farm->id, $guide->id, $closed->id]);

        $this->assertSame(2, $out['refused']);
        $this->assertSame(['red', 'Sayfada link satışı: tanitim yazisi, dofollow, backlink paket'], [$seller->fresh()->quality, $seller->fresh()->quality_note]);
        $this->assertSame(['red', 'Sayfa 160 farklı siteye link veriyor'], [$farm->fresh()->quality, $farm->fresh()->quality_note]);
        $this->assertSame(BacklinkSource::DROPPED, $row->fresh()->status, 'a refused source leaves the desks');
        $this->assertSame(['bekliyor', 'Açılmadı (1)'], [$closed->fresh()->quality, $closed->fresh()->quality_note]);
        $this->assertSame('bekliyor', $guide->fresh()->quality, 'no AI connected: the guide keeps waiting for a verdict');

        $signals = $gate->signals($guide);
        $this->assertSame(['Ankara Firma Rehberi', 'Ankara işletmeleri', 0, []], [$signals['title'], $signals['description'], $signals['external_sites'], $signals['sells']]);

        $this->assertSame(1, $gate->apply([['id' => $guide->id, 'verdict' => 'uygun', 'note' => 'Gerçek şehir rehberi.', 'kind' => 'rehber', 'city' => 'Ankara', 'apply_via' => 'form'],
            ['id' => $seller->id, 'verdict' => 'uygun', 'note' => 'x', 'kind' => 'dizin', 'city' => null, 'apply_via' => 'form']]));
        $guide->refresh();
        $this->assertSame(['uygun', 'rehber', 'ankara', 'form', 'Claude: Gerçek şehir rehberi.'], [$guide->quality, $guide->kind, $guide->city, $guide->apply_via, $guide->quality_note]);
        $this->assertSame('red', $seller->fresh()->quality, 'a refused source is not reopened by a verdict');

        // A site that does not open three times is refused.
        $closed->forceFill(['quality_note' => 'Açılmadı (2)'])->save();
        $gate->check([$closed->id]);
        $this->assertSame(['red', 'Site 3 kez açılmadı'], [$closed->fresh()->quality, $closed->fresh()->quality_note]);
    }

    public function test_a_candidate_waits_for_the_gate_and_an_empty_pool_asks_for_a_discovery_once_a_month(): void
    {
        $pool = LinkSource::query()->create(['domain' => 'yeni.example', 'name' => 'Yeni', 'url' => 'https://yeni.example/', 'kind' => 'dizin', 'sector_id' => $this->dental->id, 'quality' => 'bekliyor']);
        $waiting = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Yeni', 'url' => 'https://yeni.example/', 'domain' => 'yeni.example',
            'status' => 'yok', 'origin' => 'ai', 'link_source_id' => $pool->id]);
        foreach (LinkCitations::NATIONAL as $domain => $c) {
            LinkSource::query()->create(['domain' => $domain, 'name' => $c[0], 'url' => $c[1], 'kind' => $c[2], 'quality' => 'red']);
        }

        $out = app(LinkDesk::class)->fill($this->brand->fresh());

        $this->assertSame(0, $out['added']);
        $this->assertNull($waiting->fresh()->material_status, 'an unchecked source is not offered');
        $this->assertFalse($out['discovery'], 'a source still in the gate of the sector is enough for now');

        $pool->forceFill(['quality' => 'uygun'])->save();
        $out = app(LinkDesk::class)->fill($this->brand->fresh());
        $this->assertSame(1, $out['added']);
        $this->assertSame('queued', $waiting->fresh()->material_status);
        $this->assertTrue($out['discovery'], 'nothing left in the pool: Claude looks for the city\'s sources');
        Queue::assertPushed(ProposeBacklinkSourcesJob::class, 1);
        $this->assertFalse(app(LinkDesk::class)->fill($this->brand->fresh())['discovery'], 'once a month');
    }

    public function test_the_clients_form_turns_relations_into_facts_and_desk_rows(): void
    {
        Livewire::test(LinkDeskPage::class)->set('tab', 'hedefler')->call('surveyLink', $this->brand->id)->assertSee('/baglanti-kaynaklari/');
        $survey = LinkSurvey::query()->where('brand_id', $this->brand->id)->sole();

        $this->get(route('link-survey', ['token' => $survey->token]))->assertOk()->assertSee('Üye olduğunuz dernek')->assertSee('noindex', false);
        $this->get('/baglanti-kaynaklari/yanlis')->assertNotFound();

        $this->post(route('link-survey.store', ['token' => $survey->token]), ['answers' => [
            'memberships' => "Ankara Diş Hekimleri Odası\nAnkara Diş Hekimleri Odası\n",
            'suppliers' => 'Straumann implant',
            'press' => 'https://www.yerelhaber.example/panorama-roportaj röportaj',
        ], 'note' => 'Kongre listesi sonra'])->assertRedirect(route('link-survey', ['token' => $survey->token]));

        $survey->refresh();
        $this->assertSame([3, 'Kongre listesi sonra'], [$survey->rows_created, $survey->note]);
        $rows = BacklinkSource::query()->where('brand_id', $this->brand->id)->orderBy('id')->get();
        $this->assertSame(['Ankara Diş Hekimleri Odası', 'Straumann implant', 'röportaj'], $rows->pluck('name')->all());
        $this->assertSame([['iliski', 'sen', '', 'musteri'], ['iliski', 'sen', '', 'musteri'], ['anilma', 'masa', 'https://www.yerelhaber.example/panorama-roportaj', 'musteri']],
            $rows->map(fn (BacklinkSource $r): array => [$r->engine, $r->lane, $r->url, $r->origin])->all());
        $this->assertStringEndsWith('yerelhaber.example', $rows[2]->domain);
        $facts = BrandEpicrisisItem::query()->where('brand_id', $this->brand->id)->where('source_kind', 'customer')->pluck('value', 'field')->all();
        $this->assertSame(['certificate' => 'Ankara Diş Hekimleri Odası', 'material' => 'Straumann implant', 'relation' => 'https://www.yerelhaber.example/panorama-roportaj röportaj'], $facts);

        // Sent again: nothing is added twice.
        $this->post(route('link-survey.store', ['token' => $survey->token]), ['answers' => ['suppliers' => 'Straumann implant']]);
        $this->assertSame(3, BacklinkSource::query()->where('brand_id', $this->brand->id)->count());

        // The client's rows come first on the desk.
        app(LinkDesk::class)->fill($this->brand->fresh());
        $this->assertSame(3, BacklinkSource::query()->where('brand_id', $this->brand->id)->where('origin', 'musteri')->where('material_status', 'queued')->count());
        Queue::assertPushed(WriteLinkPack::class);
    }
}
