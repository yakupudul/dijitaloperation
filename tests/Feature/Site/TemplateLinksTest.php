<?php

namespace Tests\Feature\Site;

use App\Services\Site\TemplateLinks;
use Illuminate\Support\Facades\DB;

/** Pool #56: links repeated on most pages are kept once per site, their per-page rows go, the crawl skips them. */
final class TemplateLinksTest extends SiteTestCase
{
    public function test_a_link_on_most_pages_is_kept_once_and_its_page_rows_go_but_the_home_page_keeps_its_own(): void
    {
        $home = 'https://example.com/';
        $rows = [];
        $edge = function (string $source, string $target) use (&$rows): void {
            $rows[] = ['digital_asset_id' => $this->site->id, 'edge_key' => hash('sha256', $source.'>'.$target), 'source_url' => $source,
                'target_url' => $target, 'normalized_target_url' => $target, 'is_internal' => true, 'observed_at' => now(), 'contract_version' => 1,
                'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('b', 64)];
        };
        $edge($home, 'https://example.com/soru/ortak');
        for ($i = 1; $i <= 60; $i++) {
            $page = 'https://example.com/soru/'.$i;
            $edge($page, 'https://example.com/soru/ortak');
            if ($i <= 10) {
                $edge($page, 'https://example.com/implant');
            }
        }
        DB::table('website_link_edge')->insert($rows);

        $out = app(TemplateLinks::class)->compact((int) $this->site->id, $home);

        $this->assertSame(1, $out['templates']);
        $this->assertSame(60, $out['deleted']);
        $this->assertSame(1, DB::table('website_link_edge')->where('normalized_target_url', 'https://example.com/soru/ortak')->count(), 'the home page keeps its link');
        $this->assertSame(10, DB::table('website_link_edge')->where('normalized_target_url', 'https://example.com/implant')->count(), 'a content link stays per page');
        $this->assertSame(61, (int) DB::table('website_template_links')->value('source_pages'));
        $this->assertTrue(TemplateLinks::isTemplate((int) $this->site->id, 'https://example.com/soru/ortak/'));

        $kept = TemplateLinks::strip((int) $this->site->id, 'https://example.com/soru/99', $home, [
            ['normalized_target_url' => 'https://example.com/soru/ortak'], ['normalized_target_url' => 'https://example.com/implant'],
        ]);
        $this->assertSame([['normalized_target_url' => 'https://example.com/implant']], $kept);
        $this->assertCount(2, TemplateLinks::strip((int) $this->site->id, $home, $home, [
            ['normalized_target_url' => 'https://example.com/soru/ortak'], ['normalized_target_url' => 'https://example.com/implant'],
        ]), 'the home page keeps every link');
    }

    public function test_a_small_site_gets_no_template_links(): void
    {
        DB::table('website_link_edge')->insert(['digital_asset_id' => $this->site->id, 'edge_key' => str_repeat('a', 64), 'source_url' => 'https://example.com/a',
            'target_url' => 'https://example.com/b', 'normalized_target_url' => 'https://example.com/b', 'is_internal' => true, 'observed_at' => now(),
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('b', 64)]);

        $this->assertSame(['templates' => 0, 'deleted' => 0, 'dropped' => 0], app(TemplateLinks::class)->compact((int) $this->site->id, 'https://example.com/'));
        $this->assertSame(1, DB::table('website_link_edge')->count());
    }
}
