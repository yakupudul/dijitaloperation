<?php

namespace Tests\Feature\Site;

use App\Models\Backlink;
use App\Models\Suggestion;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\WebHealthAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Backlink Faz 1 (2026-11-05): pages other sites link to keep their value. A linked address that no longer opens gets a
 * 301 first, a chain or a page closed to Google becomes an operator task, and the most linked healthy pages pass their
 * strength to a service page through one internal link ("link köprüsü").
 */
final class BacklinkFaz1Test extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_linked_addresses_that_do_not_open_are_redirected_first_and_chains_and_closed_pages_listed(): void
    {
        $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        $this->page('/eski-kampanya/', 'Eski kampanya', ['is_indexable' => false]);
        $this->link('https://haber.example/a', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        $this->link('https://rehber.example/b', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        $this->link('https://forum.example/c', 'https://panorama.com.tr/kapandi-sayfa/');
        $this->link('https://blog.example/d', 'https://panorama.com.tr/eski-adres/');
        $this->link('https://dernek.example/e', 'https://panorama.com.tr/eski-kampanya/');
        $this->link('https://kaldirilmis.example/f', 'https://panorama.com.tr/kaldirilan/', 'kaldirildi');
        Http::fake([
            'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/' => Http::response('', 404),
            'https://panorama.com.tr/kapandi-sayfa/' => Http::response('', 410),
            'https://panorama.com.tr/eski-adres/' => Http::response('', 301, ['Location' => '/ara-adres/']),
            'https://panorama.com.tr/ara-adres/' => Http::response('', 301, ['Location' => 'https://panorama.com.tr/implant-tedavisi/']),
            '*' => Http::response('<html></html>', 200, ['Strict-Transport-Security' => 'max-age=31536000']),
        ]);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $gone = $rows['Dış link alan adres açılmıyor: /ankara-implant-tedavisi-fiyat/'];
        $this->assertSame(1, Suggestion::query()->findOrFail($gone['id'])->priority);
        $this->assertContains('2 siteden dış link alıyor: haber.example, rehber.example', $gone['before']);
        $this->assertContains('301 → https://panorama.com.tr/implant-tedavisi/', $gone['after']);
        $this->assertSame(['/kapandi-sayfa/ → 410 · 1 siteden dış link alıyor: forum.example'], $rows['Dış link alan adres açılmıyor, benzer sayfa yok (1 adres)']['before']);
        $this->assertSame(['/eski-adres/ → 2 yönlendirme → /implant-tedavisi/ · 1 siteden dış link alıyor: blog.example'], $rows['Dış link alan adres zincirle yönleniyor (1 adres)']['before']);
        $this->assertSame(['/eski-kampanya/ (noindex) · 1 siteden dış link alıyor: dernek.example'], $rows['Dış link alan sayfa Google\'a kapalı (1 sayfa)']['before']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'kaldirilan'));

        // The next night reads the kept results: no linked address is opened again.
        $linked = fn (): int => Http::recorded(fn ($request): bool => preg_match('#/(ankara-implant-tedavisi-fiyat|kapandi-sayfa|eski-adres|ara-adres)/#', $request->url()) === 1)->count();
        $before = $linked();
        app(WebHealthAudit::class)->audit($this->site);
        $this->assertSame($before, $linked());
    }

    public function test_a_gsc_404_other_sites_link_to_comes_first_and_is_not_repeated(): void
    {
        $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        $this->link('https://haber.example/a', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        DB::table('gsc_url_inspection_snapshot')->insert(['external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
            'page' => 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/', 'inspected_at' => now()->subDay(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => json_encode(['verdict' => 'FAIL', 'coverage_state' => 'Not found (404)'])]);
        Http::fake(['*' => Http::response('<html></html>', 200, ['Strict-Transport-Security' => 'max-age=31536000'])]);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $row = $rows['Google 404 görüyor: /ankara-implant-tedavisi-fiyat/'];
        $this->assertSame(1, Suggestion::query()->findOrFail($row['id'])->priority);
        $this->assertContains('1 siteden dış link alıyor: haber.example', $row['before']);
        $this->assertFalse($rows->has('Dış link alan adres açılmıyor: /ankara-implant-tedavisi-fiyat/'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'fiyat'));
    }

    public function test_the_most_linked_post_gets_one_internal_link_to_the_closest_service_page(): void
    {
        $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'wp_post_id' => 43]);
        $post = $this->page('/blog/implant-sonrasi-beslenme/', 'İmplant sonrası beslenme | Panorama', ['category' => 'blog', 'wp_post_id' => 77]);
        $this->link('https://haber.example/a', 'https://panorama.com.tr/blog/implant-sonrasi-beslenme/');
        $this->link('https://forum.example/b', 'https://panorama.com.tr/blog/implant-sonrasi-beslenme/');
        Http::fake(['*' => Http::response('<html></html>', 200, ['Strict-Transport-Security' => 'max-age=31536000'])]);

        app(WebHealthAudit::class)->audit($this->site);

        $bridge = Suggestion::query()->where('decision_key', 'repair.web_health.bridge')->sole();
        $this->assertSame(['internal_links', $post->id, 'https://panorama.com.tr/implant-tedavisi/', Suggestion::OPEN],
            [$bridge->action_type, $bridge->page_id, data_get($bridge->action, 'link_to'), $bridge->status]);
        $this->assertStringContainsString('2 siteden dış link', $bridge->reason);

        // Once the post links there, the suggestion closes by itself.
        DB::table('website_link_edge')->insert(['digital_asset_id' => $this->site->id, 'edge_key' => Str::random(20), 'source_url' => 'https://panorama.com.tr/blog/implant-sonrasi-beslenme/',
            'target_url' => 'https://panorama.com.tr/implant-tedavisi/', 'normalized_target_url' => 'https://panorama.com.tr/implant-tedavisi/', 'is_internal' => true,
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        app(WebHealthAudit::class)->audit($this->site);
        $this->assertSame(Suggestion::APPLIED, $bridge->fresh()->status);
    }

    private function link(string $from, string $to, string $status = 'aktif'): void
    {
        Backlink::query()->create(['brand_id' => $this->brand->id, 'source_url' => $from, 'source_domain' => (string) parse_url($from, PHP_URL_HOST),
            'target_url' => $to, 'link_hash' => hash('sha256', $from.'|'.$to), 'first_seen' => now()->toDateString(), 'source' => 'bing', 'status' => $status]);
    }
}
