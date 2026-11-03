<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Website\V2\BacklinksTab;
use App\Models\AgencySetting;
use App\Models\Backlink;
use App\Services\Intel\SerpResults;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\WebHealthAudit;
use App\Services\Site\SiteDomains;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;

/**
 * Backlink Faz 0 (yakup 2026-10-26, "3 Kasım'da yap"): who links to the site is read every week from Bing Webmaster (with
 * the link text; a link Bing stops listing is marked lost), a site Google shows under a second address that does not
 * 301 to the main one becomes an operator task, and a top-10 pasted from Google replaces the paid SERP.
 */
final class BacklinkFaz0Test extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_bing_links_are_read_weekly_with_the_link_text_and_a_link_bing_drops_is_marked_lost(): void
    {
        AgencySetting::query()->updateOrCreate([], ['bing_webmaster_api_key' => 'anahtar']);
        DB::table('bing_sites')->insert(['digital_asset_id' => $this->site->id, 'site_url' => 'https://panorama.com.tr/', 'verified' => true, 'created_at' => now(), 'updated_at' => now()]);
        $links = [
            ['__type' => 'LinkDetail', 'Url' => 'https://haber.example/ankara-dis-klinikleri', 'AnchorText' => 'Panorama Ankara'],
            ['Url' => 'https://rehber.example/ankara/dis', 'AnchorText' => ''],
            ['Url' => 'https://www.panorama.com.tr/blog/', 'AnchorText' => 'iç bağlantı'],
        ];
        Http::fake(function (Request $request) use (&$links) {
            return match (true) {
                str_contains($request->url(), 'GetLinkCounts') => Http::response(['d' => ['__type' => 'LinkCounts', 'Links' => [
                    ['Url' => 'https://panorama.com.tr/', 'Count' => 3]], 'TotalPages' => 1]]),
                str_contains($request->url(), 'GetUrlLinks') => Http::response(['d' => ['Details' => $links, 'TotalPages' => 1]]),
                default => Http::response('', 404),
            };
        });

        $this->artisan('moxdop:bing:backlinks')->expectsOutputToContain('2 yeni')->assertSuccessful();

        $rows = Backlink::query()->where('brand_id', $this->brand->id)->orderBy('source_domain')->get();
        $this->assertSame(['haber.example', 'rehber.example'], $rows->pluck('source_domain')->all(), 'the own domain is never a backlink');
        $this->assertSame(['bing', 'Panorama Ankara', 'https://panorama.com.tr/'], [$rows[0]->source, $rows[0]->anchor, $rows[0]->target_url]);
        $this->assertNull($rows[1]->anchor);
        $this->assertNotNull(DB::table('bing_sites')->value('links_collected_at'));

        $links = [$links[0]];
        $this->artisan('moxdop:bing:backlinks')->expectsOutputToContain('1 kaybolan')->assertSuccessful();
        $lost = Backlink::query()->where('source_domain', 'rehber.example')->sole();
        $this->assertSame(['kaldirildi', now()->toDateString()], [$lost->status, $lost->lost_at?->toDateString()]);
        $this->assertSame('aktif', Backlink::query()->where('source_domain', 'haber.example')->value('status'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');

        Livewire::test(BacklinksTab::class, ['assetId' => $this->site->id])
            ->assertSee('1 site bağlantı veriyor')->assertSee('Panorama Ankara')->assertSee('kayboldu');
    }

    public function test_a_brandless_site_is_not_read(): void
    {
        AgencySetting::query()->updateOrCreate([], ['bing_webmaster_api_key' => 'anahtar']);
        $this->site->forceFill(['brand_id' => null])->save();
        DB::table('bing_sites')->insert(['digital_asset_id' => $this->site->id, 'site_url' => 'https://panorama.com.tr/', 'verified' => true, 'created_at' => now(), 'updated_at' => now()]);
        Http::fake();

        $this->artisan('moxdop:bing:backlinks')->expectsOutputToContain('0 site')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_second_address_google_shows_without_a_301_becomes_an_operator_task(): void
    {
        foreach (['https://panorama.com.tr/implant/' => 900, 'http://www.panorama.com.tr/' => 400, 'https://www.panorama.com.tr/zirkonyum/' => 50] as $page => $impressions) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
                'search_type' => 'web', 'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'implant', 'page' => $page, 'clicks' => 7, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }
        Http::fake([
            'http://www.panorama.com.tr/' => Http::response('<html></html>', 200),
            'https://www.panorama.com.tr/' => Http::response('', 301, ['Location' => 'https://panorama.com.tr/']),
            '*' => Http::response('<html></html>', 200, ['Strict-Transport-Security' => 'max-age=31536000']),
        ]);

        app(WebHealthAudit::class)->audit($this->site);

        $row = app(RepairDesk::class)->rows()->keyBy('title')['Site birden çok adreste açılıyor (1 adres)'];
        $this->assertSame(RepairDesk::MANUAL, $row['risk']);
        $this->assertSame(['http://www.panorama.com.tr → 200 ile açılıyor, ana adrese yönlenmiyor · son 28 günde 7 tıklama, 400 gösterim'], $row['before']);
        $this->assertContains('RewriteRule ^(.*)$ https://panorama.com.tr/$1 [L,R=301]', $row['after']);
        $this->assertContains('RewriteCond %{HTTP_HOST} !^panorama\.com\.tr$ [NC]', $row['after']);
    }

    public function test_a_pasted_top_ten_is_kept_a_quarter_and_read_without_a_paid_call(): void
    {
        $pasted = "Panorama Ankara\nhttps://panorama.com.tr › tedaviler › implant\nAnkara implant fiyatları Dr.Ahmet anlatıyor\n"
            ."Doktortakvimi\nwww.doktortakvimi.com › ankara › implantoloji\nhttps://www.google.com/search?q=x\n"
            ."https://www.dentaturk.com/ankara/implant-tedavisi?x=1\nhurriyet.com.tr › yerel-haberler › …\nhttps://www.dentaturk.com/ankara/implant-tedavisi?x=1\n";
        $results = SerpResults::parsePasted($pasted);
        $this->assertSame([
            'https://panorama.com.tr/tedaviler/implant', 'https://www.doktortakvimi.com/ankara/implantoloji',
            'https://www.dentaturk.com/ankara/implant-tedavisi?x=1', 'https://hurriyet.com.tr/',
        ], array_column($results, 'url'), 'titles, Google and repeats are left out; a shortened path keeps only the site');
        $this->assertSame([1, 2, 3, 4], array_column($results, 'rank'));

        Http::fake();
        $serp = app(SerpResults::class);
        $this->assertSame(4, $serp->storeManual('Ankara İmplant', 2792, 'tr', 'mobile', $pasted));
        $this->travel(90)->days();
        $top = $serp->topTen('ankara implant', 2792, 'tr', 'mobile');
        $this->assertTrue($top['cached']);
        $this->assertSame('doktortakvimi.com', SiteDomains::host($top['results'][1]['url']));
        Http::assertNothingSent();

        $this->travel(40)->days();
        try {
            $serp->topTen('ankara implant', 2792, 'tr', 'mobile');
            $this->fail('an old paste is not used and no paid call is made without a connection');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('ilk 10 adresi yapıştırın', $error->getMessage());
        }
        Http::assertNothingSent();
    }
}
