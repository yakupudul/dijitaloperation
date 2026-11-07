<?php

namespace Tests\Feature\Website;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Website\Pages\RedirectedAddresses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin audit 2026-11-07 V2: an address whose fetch ended on another address is not a page (moximu.com: 1,020 old URLs, all 301). */
final class RedirectedAddressesTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = DigitalAsset::factory()->create(['type' => 'website', 'status' => 'active', 'domain' => 'moximu.example', 'primary_url' => 'https://moximu.example/']);
        $this->site->brand_id ??= Brand::factory()->create()->id;
        $this->site->save();
    }

    public function test_same_page_redirects_are_not_moves(): void
    {
        $this->assertFalse(RedirectedAddresses::redirectedElsewhere('http://www.moximu.example/a/', ['final_url' => 'https://moximu.example/a', 'redirect_count' => 2]), 'https + www + slash');
        $this->assertFalse(RedirectedAddresses::redirectedElsewhere('https://moximu.example/a', ['final_url' => 'https://moximu.example/b', 'redirect_count' => 0]), 'no redirect followed');
        $this->assertFalse(RedirectedAddresses::redirectedElsewhere('https://moximu.example/a', ['final_url' => null, 'status_code' => 200]));
        $this->assertTrue(RedirectedAddresses::redirectedElsewhere('https://moximu.example/eski/', ['final_url' => 'https://moximu.example/hizmetlerimiz/', 'redirect_count' => 1]));
        $this->assertTrue(RedirectedAddresses::redirectedElsewhere('https://moximu.example/en/', ['final_url' => 'https://baska.example/', 'redirect_count' => 1]), 'another host');
    }

    public function test_sweep_drops_redirected_addresses_from_the_crawl_list_and_the_inventory_but_keeps_published_posts(): void
    {
        // Latest fetch per address: /eski-hizmet and /genel/crm moved (the first one fetched twice, the old copy said 200).
        $this->http('/eski-hizmet', '2026-11-01 05:00:00', ['status_code' => 200, 'final_url' => 'https://moximu.example/eski-hizmet', 'redirect_count' => 0]);
        $this->http('/eski-hizmet', '2026-11-07 05:00:00', ['status_code' => 200, 'final_url' => 'https://moximu.example/hizmetlerimiz/klinik-web-sitesi/', 'redirect_count' => 1]);
        $this->http('/genel/crm', '2026-11-07 05:00:00', ['status_code' => 200, 'final_url' => 'https://moximu.example/hizmetlerimiz/', 'redirect_count' => 1]);
        $this->http('/yayinda-ama-yonlenen', '2026-11-07 05:00:00', ['status_code' => 200, 'final_url' => 'https://moximu.example/', 'redirect_count' => 1]);
        $this->http('/hizmetlerimiz/', '2026-11-07 05:00:00', ['status_code' => 200, 'final_url' => 'https://moximu.example/hizmetlerimiz/', 'redirect_count' => 0]);
        $this->http('/http-yonlenen', '2026-11-07 05:00:00', ['status_code' => 200, 'final_url' => 'https://www.moximu.example/http-yonlenen/', 'redirect_count' => 1]);
        foreach (['/eski-hizmet', '/genel/crm', '/hizmetlerimiz', '/http-yonlenen', '/yayinda-ama-yonlenen'] as $path) {
            $this->url($path);
        }
        $old = $this->page('/eski-hizmet');
        $crm = $this->page('/genel/crm');
        $live = $this->page('/hizmetlerimiz/');
        $published = $this->page('/yayinda-ama-yonlenen', 44);
        $unpublished = $this->page('/genel/crm-2', 45);
        $this->http('/genel/crm-2', '2026-11-07 05:00:00', ['status_code' => 200, 'final_url' => 'https://moximu.example/', 'redirect_count' => 1]);
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '44', 'status' => 'publish',
            'title' => 'Yayında', 'permalink' => 'https://moximu.example/yayinda-ama-yonlenen', 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'p44'), 'created_at' => now(), 'updated_at' => now()]);
        $work = Suggestion::query()->create([
            'brand_id' => $this->site->brand_id, 'channel' => 'website', 'target_type' => 'website', 'target_id' => $this->site->id, 'page_id' => $old->id, 'decision_key' => 'site.meta',
            'fingerprint' => hash('sha256', 'w'), 'material_hash' => 'x', 'title' => 'Meta açıklaması yok', 'reason' => 'x', 'priority' => 1, 'action_type' => 'wp_fix',
            'status' => Suggestion::OPEN, 'action' => [], 'evidence' => [],
        ]);

        $result = app(RedirectedAddresses::class)->sweep();

        $this->assertSame(['sites' => 1, 'urls' => 3, 'pages' => 3], $result);
        $this->assertSame(['/hizmetlerimiz', '/http-yonlenen'], DB::table('website_url')->where('digital_asset_id', $this->site->id)->orderBy('normalized_url')->pluck('normalized_url')
            ->map(fn (string $url): string => parse_url($url, PHP_URL_PATH))->all(), 'the crawl list keeps live pages and same-page redirects');
        $this->assertNull($old->fresh(), 'URL row of a moved address');
        $this->assertNull($crm->fresh());
        $this->assertNull($unpublished->fresh(), 'a WordPress row the site no longer publishes');
        $this->assertNotNull($live->fresh());
        $this->assertNotNull($published->fresh(), 'a published post that redirects stays: the screen shows it as 301');
        $this->assertNull(Suggestion::query()->whereKey($work->id)->value('page_id'), 'its open work lost its page (closed as page gone by the store)');
        $this->assertSame(['sites' => 0, 'urls' => 0, 'pages' => 0], app(RedirectedAddresses::class)->sweep(), 'a second pass has nothing to do');
        $this->artisan('moxdop:website:drop-redirected', ['--site' => $this->site->id])->expectsOutputToContain('0 sitede')->assertSuccessful();
    }

    private function page(string $path, ?int $wpId = null): Page
    {
        $url = 'https://moximu.example'.$path;

        return Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'path' => $path, 'title' => $path,
            'category' => null, 'wp_post_id' => $wpId, 'wp_post_type' => $wpId !== null ? 'page' : null]);
    }

    private function url(string $path): void
    {
        $url = 'https://moximu.example'.$path;
        DB::table('website_url')->insert(['digital_asset_id' => $this->site->id, 'asset_id' => (string) $this->site->id, 'normalized_url' => $url, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $url), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $metadata */
    private function http(string $path, string $observedAt, array $metadata): void
    {
        DB::table('website_http_snapshot')->insert(['digital_asset_id' => $this->site->id, 'url' => 'https://moximu.example'.$path, 'observed_at' => $observedAt, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(40), 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()]);
    }
}
