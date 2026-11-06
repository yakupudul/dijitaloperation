<?php

namespace Tests\Feature\Site;

use App\Enums\CustomerStatus;
use App\Jobs\Site\VerifyBacklinkSourceJob;
use App\Jobs\Site\WriteLinkPack;
use App\Livewire\Operator\Links\LinkDeskPage;
use App\Livewire\Operator\Links\TodayLinksCard;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\LinkSource;
use App\Models\LinkTarget;
use App\Models\Page;
use App\Services\Site\Backlinks\BacklinkVerifier;
use App\Services\Site\Backlinks\LinkDesk;
use App\Services\Site\Backlinks\LinkPackWriter;
use App\Services\Site\Backlinks\LinkTargets;
use App\Services\Website\PageFetcher;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

/**
 * Backlink hizmeti, adım 1 (yakup 2026-11-06 "Sırayla hepsini yap"): the pages that need link strength are picked by
 * rules, the desk is filled up to the brand's package (lost links first, then its candidates, then the shared pool of
 * its sector and city), each row's material is written, and the operator applies, reports the link and the system
 * verifies it; the pool learns what each source brings.
 */
final class LinkDeskTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_target_pages_are_service_pages_on_page_one_or_two_with_demand_and_fitting_content(): void
    {
        $implant = $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $zirkon = $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama', ['category' => 'hizmet']);
        $top = $this->page('/dis-beyazlatma/', 'Diş Beyazlatma', ['category' => 'hizmet']);
        $thin = $this->page('/lamina/', 'Lamina', ['category' => 'hizmet']);
        $this->row($this->cluster($this->implant, 'Ankara implant', ['ankara implant']), $implant, 9.4, 800, 'ankara implant');
        $this->row($this->cluster($this->zirkonyum, 'Zirkonyum', ['zirkonyum kaplama']), $zirkon, 6.0, 500, 'zirkonyum kaplama');
        $this->row($this->cluster($this->implant, 'İmplant fiyat', ['implant fiyat']), $top, 2.1, 900, 'implant fiyat');
        $this->row($this->cluster($this->zirkonyum, 'Lamina', ['lamina']), $thin, 8.0, 900, 'lamina', ['state' => 'thin_coverage']);
        Backlink::query()->create(['brand_id' => $this->brand->id, 'source_url' => 'https://haber.example/a', 'source_domain' => 'haber.example',
            'target_url' => 'https://panorama.com.tr/zirkonyum-kaplama/', 'link_hash' => hash('sha256', 'a'), 'source' => 'bing', 'status' => 'aktif', 'anchor' => 'Panorama']);

        $out = app(LinkTargets::class)->refresh($this->brand);

        $this->assertSame(2, $out['picked']);
        $targets = LinkTarget::query()->orderBy('rank')->get();
        $this->assertSame(['/implant-tedavisi/', '/zirkonyum-kaplama/'], $targets->map->path()->all(), 'the main service comes first; a top-3 page and thin content are left out');
        $this->assertStringContainsString('"ankara implant" aramasında ortalama 9,4. sırada', $targets[0]->reason);
        $this->assertStringContainsString('henüz hiçbir site', $targets[0]->reason);
        $this->assertSame(1, $targets[1]->referring_domains);
        $this->assertSame([9.4, 800], [$targets[0]->before['position'], $targets[0]->before['impressions']]);
        $plan = collect($targets[0]->anchor_plan)->keyBy('kind');
        $this->assertSame(['Panorama Ankara', 'panorama.com.tr', 'ankara implant'], [$plan['marka']['text'], $plan['adres']['text'], $plan['anahtar']['text']]);
        $this->assertSame(10, $plan['anahtar']['share']);
        $this->assertSame(100, collect($targets[0]->anchor_plan)->sum('share'));

        // A target that reached the top 3 is done, with the after numbers.
        BrandClusterPage::query()->where('page_id', $implant->id)->update(['position_28d' => 2.5]);
        $this->assertSame(1, app(LinkTargets::class)->refresh($this->brand)['done']);
        $this->assertSame([LinkTarget::DONE, 2.5], [$targets[0]->fresh()->status, $targets[0]->fresh()->after['position'] ?? null]);
    }

    public function test_the_desk_fills_up_to_the_package_with_lost_links_then_candidates_then_the_pool_of_the_sector_and_city(): void
    {
        $implant = $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $this->row($this->cluster($this->implant, 'Ankara implant', ['ankara implant']), $implant, 9.4, 800, 'ankara implant');
        Backlink::query()->create(['brand_id' => $this->brand->id, 'source_url' => 'https://eski.example/yazi', 'source_domain' => 'eski.example',
            'target_url' => 'https://panorama.com.tr/implant-tedavisi/', 'link_hash' => hash('sha256', 'eski'), 'source' => 'bing', 'status' => 'kaldirildi',
            'lost_at' => now()->subDays(5)->toDateString(), 'anchor' => 'Panorama implant']);
        $other = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Başka Klinik', 'sector_id' => $this->dental->id]);
        DigitalAsset::factory()->create(['brand_id' => $other->id, 'type' => 'website', 'name' => 'baskaklinik.com', 'domain' => 'baskaklinik.com', 'primary_url' => 'https://baskaklinik.com/']);
        $pool = fn (string $domain, array $extra = []): LinkSource => LinkSource::query()->create(array_merge(['domain' => $domain, 'name' => $domain,
            'url' => 'https://'.$domain.'/kayit', 'kind' => 'rehber', 'sector_id' => $this->dental->id, 'city' => 'ankara', 'quality' => 'uygun'], $extra));
        $pool('ankararehber.example', ['won_count' => 3, 'applied_count' => 4]);
        $pool('dishekimleri.example', ['kind' => 'dernek', 'city' => null]);
        $pool('izmirrehber.example', ['city' => 'izmir']);
        $pool('linksatan.example', ['quality' => 'red']);
        $pool('baskaklinik.com');
        $pool('hukuk.example', ['sector_id' => null, 'city' => null, 'kind' => 'dizin', 'won_count' => 0]);
        BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Aday', 'url' => 'https://aday.example/', 'domain' => 'aday.example', 'kind' => 'dizin', 'status' => 'yok', 'origin' => 'manual']);
        $this->brand->forceFill(['link_package' => 'temel'])->save();

        $out = app(LinkDesk::class)->fill($this->brand->fresh());

        $this->assertSame(5, $out['added'], 'every fitting source fits in the room of Temel (2 links a month × 3 open rows)');
        $rows = BacklinkSource::query()->where('brand_id', $this->brand->id)->orderBy('id')->get()->keyBy('domain');
        $this->assertEqualsCanonicalizing(['aday.example', 'eski.example', 'ankararehber.example', 'dishekimleri.example', 'hukuk.example'], $rows->keys()->all());
        $this->assertFalse($rows->has('izmirrehber.example'), 'another city\'s source is not offered');
        $this->assertFalse($rows->has('linksatan.example'), 'a refused source is never offered');
        $this->assertFalse($rows->has('baskaklinik.com'), 'our brands never link to each other');
        $this->assertSame(['geri_kazanim', 'Panorama implant', '/implant-tedavisi/'], [$rows['eski.example']->engine, $rows['eski.example']->anchor, $rows['eski.example']->target?->path()]);
        $this->assertSame(['kunye', null, 'Panorama Ankara', 'masa'], [$rows['ankararehber.example']->engine, $rows['ankararehber.example']->link_target_id, $rows['ankararehber.example']->anchor, $rows['ankararehber.example']->lane]);
        $this->assertSame(['iliski', 'sen', '/implant-tedavisi/'], [$rows['dishekimleri.example']->engine, $rows['dishekimleri.example']->lane, $rows['dishekimleri.example']->target?->path()]);
        $this->assertSame('queued', $rows['aday.example']->material_status, 'the brand\'s own candidate is taken onto the desk');
        Queue::assertPushed(WriteLinkPack::class, 5);
        $this->assertSame(['budget' => 2, 'won' => 0, 'open' => 5, 'room' => 1], app(LinkDesk::class)->month($this->brand->fresh()));

        // One more pool source: only the room left is used.
        $pool('ankaradis.example');
        $pool('cankayarehber.example');
        $this->assertSame(1, app(LinkDesk::class)->fill($this->brand->fresh())['added']);
        $this->assertSame(0, app(LinkDesk::class)->month($this->brand->fresh())['room']);

        // A closed package fills nothing.
        $this->brand->forceFill(['link_package' => 'kapali'])->save();
        $this->assertSame(0, LinkDesk::budget($this->brand->fresh()));
    }

    public function test_material_is_stored_and_an_unfit_source_is_set_aside(): void
    {
        $row = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'domain' => 'rehber.example',
            'status' => 'yok', 'material_status' => 'running', 'anchor' => 'Panorama Ankara']);
        $writer = app(LinkPackWriter::class);

        $this->assertTrue($writer->store($row, ['fit' => true, 'why_not' => '', 'short_description' => 'Ankara Çankaya\'da diş kliniği.',
            'description' => 'Uzun açıklama.', 'fields' => [['label' => 'İşletme adı', 'value' => 'Panorama Ankara'], ['label' => 'Telefon', 'value' => '']],
            'email_subject' => '', 'email_body' => '', 'phone_note' => '', 'steps' => ['Kayıt sayfasını aç']]));
        $row->refresh();
        $this->assertSame(['ready', 'hazır', [['label' => 'İşletme adı', 'value' => 'Panorama Ankara']]], [$row->material_status, $row->stepLabel(), $row->material['fields']]);

        $bad = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Link satan', 'url' => 'https://satan.example/', 'domain' => 'satan.example', 'status' => 'yok', 'material_status' => 'running']);
        $writer->store($bad, ['fit' => false, 'why_not' => 'Tanıtım yazısı satıyor.', 'short_description' => '', 'description' => '', 'fields' => [],
            'email_subject' => '', 'email_body' => '', 'phone_note' => '', 'steps' => []]);
        $this->assertSame([BacklinkSource::DROPPED, 'Claude: Tanıtım yazısı satıyor.'], [$bad->fresh()->status, $bad->fresh()->material_note]);

        $payload = $writer->payload($row->fresh(['brand', 'pool', 'target']));
        $this->assertSame(['Panorama Ankara', 'https://panorama.com.tr/', 'Panorama Ankara'], [$payload['brand']['name'], $payload['brand']['website'], $payload['target']['anchor']]);
        $this->assertContains('Diş İmplantı', $payload['brand']['services']);
    }

    public function test_applying_and_a_verified_link_teach_the_pool_and_a_paid_follow_link_is_flagged(): void
    {
        $source = LinkSource::query()->create(['domain' => 'rehber.example', 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'kind' => 'rehber', 'quality' => 'bekliyor']);
        $row = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'domain' => 'rehber.example',
            'status' => 'yok', 'material_status' => 'ready', 'link_source_id' => $source->id, 'paid' => true]);
        $desk = app(LinkDesk::class);

        $desk->sent($row->fresh());
        $this->assertSame([BacklinkSource::APPLIED, 1, 'gönderildi'], [$row->fresh()->status, $source->fresh()->applied_count, $row->fresh()->stepLabel()]);
        $this->travel(8)->days();
        $this->assertSame('yanıt bekliyor', $row->fresh()->stepLabel());

        $desk->given($row->fresh(), 'https://rehber.example/firma/panorama');
        Queue::assertPushed(VerifyBacklinkSourceJob::class);
        $fetcher = Mockery::mock(PageFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturn(['html' => '<a href="https://panorama.com.tr/" rel="nofollow">Panorama</a>']);
        $this->app->instance(PageFetcher::class, $fetcher);

        $this->assertSame(BacklinkVerifier::FOUND, app(BacklinkVerifier::class)->verify($row->fresh()));
        $row->refresh();
        $this->assertSame([BacklinkSource::VERIFIED, 'nofollow'], [$row->status, $row->rel]);
        $this->assertStringStartsWith('Ücretli link: kaynaktan rel="sponsored"', (string) $row->note);
        $source->refresh();
        $this->assertSame([1, 8, 'uygun', 100], [$source->won_count, $source->averageDays(), $source->quality, $source->winRate()]);

        // Found again on the weekly check: the pool counts it once.
        app(BacklinkVerifier::class)->verify($row->fresh());
        $this->assertSame(1, $source->fresh()->won_count);
    }

    public function test_the_desk_screen_marks_steps_and_the_pool_refuses_a_source_everywhere(): void
    {
        $source = LinkSource::query()->create(['domain' => 'rehber.example', 'name' => 'Ankara Rehberi', 'url' => 'https://rehber.example/', 'kind' => 'rehber', 'city' => 'ankara']);
        $row = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Ankara Rehberi', 'url' => 'https://rehber.example/kayit', 'domain' => 'rehber.example',
            'status' => 'yok', 'material_status' => 'ready', 'link_source_id' => $source->id, 'engine' => 'kunye', 'anchor' => 'Panorama Ankara',
            'material' => ['short' => 'Çankaya diş kliniği.', 'fields' => [['label' => 'Telefon', 'value' => '0312 000 00 00']], 'email_body' => 'Merhaba,', 'email_subject' => 'Kayıt', 'steps' => ['Kayıt sayfasını aç']]]);
        $person = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Diş Hekimleri Odası', 'url' => 'https://oda.example/', 'domain' => 'oda.example',
            'status' => 'yok', 'material_status' => 'ready', 'lane' => 'sen', 'engine' => 'iliski']);

        Livewire::test(TodayLinksCard::class)->assertSee('1 başvuru hazır')->assertSee('1 telefon / ziyaret');

        $page = Livewire::test(LinkDeskPage::class)
            ->assertSee('Ankara Rehberi')->assertSee('Diş Hekimleri Odası')->assertSee('hazır')
            ->call('toggle', $row->id)->assertSee('0312 000 00 00')->assertSee('Kayıt sayfasını aç')->assertSee('Postada aç')
            ->call('sent', $row->id)->assertSee('başvuru işaretlendi')
            ->set('linkUrl.'.$row->id, 'olmayan')->call('given', $row->id)->assertHasErrors('linkUrl.'.$row->id)
            ->set('linkUrl.'.$row->id, 'https://rehber.example/firma/panorama')->call('given', $row->id)->assertHasNoErrors();
        $this->assertSame([BacklinkSource::GIVEN, 'https://rehber.example/firma/panorama'], [$row->fresh()->status, $row->fresh()->link_url]);

        $page->set('tab', 'havuz')->assertSee('rehber.example')
            ->set('source', ['name' => 'Oda', 'url' => 'https://ankaradisodasi.example/uyelik', 'kind' => 'oda', 'city' => 'Ankara', 'apply_via' => 'uyelik', 'contact_email' => '', 'sector_id' => (string) $this->dental->id])
            ->call('addSource')->assertHasNoErrors()->assertSee('havuza eklendi');
        $added = LinkSource::query()->where('domain', 'ankaradisodasi.example')->sole();
        $this->assertSame(['ankara', 'uygun', $this->dental->id], [$added->city, $added->quality, (int) $added->sector_id]);

        $odaPool = LinkSource::query()->create(['domain' => 'oda.example', 'name' => 'Oda', 'url' => 'https://oda.example/', 'kind' => 'oda']);
        $person->forceFill(['link_source_id' => $odaPool->id])->save();
        $page->call('setQuality', $odaPool->id, 'red');
        $this->assertSame([BacklinkSource::DROPPED, 'red'], [$person->fresh()->status, $odaPool->fresh()->quality]);

        $implant = $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $this->row($this->cluster($this->implant, 'Ankara implant', ['ankara implant']), $implant, 9.4, 800, 'ankara implant');
        $page->set('tab', 'hedefler')->call('setPackage', $this->brand->id, 'buyume')->call('fillBrand', $this->brand->id)
            ->assertSee('Ankara İmplant Tedavisi')->assertSee('Büyüme · ayda 4')->assertSee('“ankara implant” %10');
        $this->assertSame('buyume', $this->brand->fresh()->link_package);

        $this->get(route('operator.links'))->assertOk()->assertSee('Bağlantılar');
    }

    /** @param  array<string, mixed>  $extra */
    private function row(Cluster $cluster, Page $page, float $position, int $impressions, string $query, array $extra = []): BrandClusterPage
    {
        $row = BrandClusterPage::query()->create(array_merge(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $page->id, 'state' => 'weak_performance', 'target_query' => $query, 'language' => 'tr', 'clicks_28d' => 12,
            'impressions_28d' => $impressions, 'position_28d' => $position], $extra));
        $row->forceFill(['coverage' => 'full'])->save();

        return $row;
    }
}
