<?php

namespace Tests\Unit;

use App\Services\SeoTasks\SiteUrlPattern;
use Tests\TestCase;

/** New-page URLs follow the site's own structure (root post slugs, /bloglar/, /tedavilerimiz/{kategori}/). */
final class SiteUrlPatternTest extends TestCase
{
    public function test_root_slug_site_never_gets_an_invented_blog_folder(): void
    {
        $pattern = new SiteUrlPattern($this->pages([
            ['/implant-tedavisi-nedir/', 'post'],
            ['/dis-beyazlatma-nasil-yapilir/', 'post'],
            ['/tedavilerimiz/implant-tedavisi/', 'page'],
            ['/iletisim/', 'page'],
        ]));

        $this->assertSame('/', $pattern->postBase());
        $this->assertSame('https://panorama.test/implant-tedavisi-sureci/', $pattern->targetUrl('https://panorama.test', 'guide', 'implant-tedavisi-sureci'));
    }

    public function test_root_slug_site_without_cms_types_uses_root_for_guides(): void
    {
        $pattern = new SiteUrlPattern($this->pages([['/implant-tedavisi-nedir/'], ['/hakkimizda/'], ['/iletisim/']]));

        $this->assertSame('/', $pattern->postBase());
        $this->assertStringNotContainsString('/blog/', $pattern->targetUrl('https://x.test', 'guide', 'implant-sureci'));
    }

    public function test_site_with_a_blog_folder_keeps_it_for_guides(): void
    {
        $pattern = new SiteUrlPattern($this->pages([['/bloglar/implant-nedir/'], ['/bloglar/dis-eti-hastaliklari/'], ['/blog/eski-yazi/'], ['/iletisim/']]));

        $this->assertSame('/bloglar/', $pattern->postBase());
        $this->assertSame('https://x.test/bloglar/implant-sureci/', $pattern->targetUrl('https://x.test/', 'guide', 'implant-sureci'));

        $wordpress = new SiteUrlPattern($this->pages([['/blog/a/', 'post'], ['/blog/b/', 'post'], ['/c/', 'post']]));
        $this->assertSame('/blog/', $wordpress->postBase());
    }

    public function test_service_pages_follow_the_dominant_service_section_and_category_folder(): void
    {
        $pattern = new SiteUrlPattern($this->pages([
            ['/tedavilerimiz/implant/tek-dis-implant/'],
            ['/tedavilerimiz/implant/all-on-four/'],
            ['/tedavilerimiz/estetik/lamina/'],
            ['/tedavilerimiz/ortodonti/'],
            ['/hakkimizda/'],
        ]));

        $this->assertSame('/tedavilerimiz/', $pattern->serviceBase('Zirkonyum Kaplama'));
        $this->assertSame('/tedavilerimiz/implant/', $pattern->serviceBase('İmplant Üstü Protez'));
        $this->assertSame('https://x.test/tedavilerimiz/zirkonyum-kaplama/', $pattern->targetUrl('https://x.test', 'service', 'zirkonyum-kaplama', 'Zirkonyum Kaplama'));
        $this->assertSame('https://x.test/ankara-zirkonyum/', $pattern->targetUrl('https://x.test', 'location', 'ankara-zirkonyum', 'Zirkonyum'));
    }

    public function test_site_without_service_section_uses_root(): void
    {
        $pattern = new SiteUrlPattern($this->pages([['/implant/'], ['/ortodonti/']]));

        $this->assertSame('/', $pattern->serviceBase('İmplant'));
        $this->assertSame('https://x.test/zirkonyum/', $pattern->targetUrl('https://x.test', 'service', 'zirkonyum', 'Zirkonyum'));
    }

    /**
     * @param  list<array{0: string, 1?: string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function pages(array $rows): array
    {
        return array_map(static fn (array $row): array => ['url' => 'https://x.test'.$row[0], 'path' => $row[0], 'cms_type' => $row[1] ?? null], $rows);
    }
}
