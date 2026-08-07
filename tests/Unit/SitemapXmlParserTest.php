<?php

namespace Tests\Unit;

use App\Support\SitemapXmlParser;
use PHPUnit\Framework\TestCase;

class SitemapXmlParserTest extends TestCase
{
    public function test_empty_urlset_is_valid(): void
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
</urlset>
XML;

        $parsed = (new SitemapXmlParser)->parse($body, 200);

        $this->assertTrue($parsed['parse_ok']);
        $this->assertFalse($parsed['malformed']);
        $this->assertSame('urlset', $parsed['root_element']);
        $this->assertSame(0, $parsed['url_count']);
        $this->assertFalse($parsed['body_truncated']);
    }

    public function test_sitemapindex_counts_child_sitemaps(): void
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://example.com/a.xml</loc></sitemap>
  <sitemap><loc>https://example.com/b.xml</loc></sitemap>
</sitemapindex>
XML;

        $parsed = (new SitemapXmlParser)->parse($body, 200);

        $this->assertTrue($parsed['parse_ok']);
        $this->assertSame('sitemapindex', $parsed['root_element']);
        $this->assertSame(2, $parsed['url_count']);
    }

    public function test_urlset_counts_url_entries(): void
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/</loc></url>
  <url><loc>https://example.com/about</loc></url>
</urlset>
XML;

        $parsed = (new SitemapXmlParser)->parse($body, 200);

        $this->assertTrue($parsed['parse_ok']);
        $this->assertSame('urlset', $parsed['root_element']);
        $this->assertSame(2, $parsed['url_count']);
    }

    public function test_non_xml_body_is_malformed(): void
    {
        $parsed = (new SitemapXmlParser)->parse('<html>not a sitemap</html>', 200);

        $this->assertTrue($parsed['malformed']);
        $this->assertFalse($parsed['parse_ok']);
        $this->assertSame('html', $parsed['root_element']);
    }

    public function test_wrong_root_element_is_malformed(): void
    {
        $parsed = (new SitemapXmlParser)->parse('<?xml version="1.0"?><rss></rss>', 200);

        $this->assertTrue($parsed['malformed']);
        $this->assertFalse($parsed['parse_ok']);
        $this->assertSame('rss', $parsed['root_element']);
    }

    public function test_non_200_status_skips_body_parsing(): void
    {
        $parsed = (new SitemapXmlParser)->parse('<urlset></urlset>', 404);

        $this->assertNull($parsed['body']);
        $this->assertFalse($parsed['parse_ok']);
        $this->assertFalse($parsed['malformed']);
    }

    public function test_oversized_body_is_truncated(): void
    {
        $raw = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .str_repeat('<!--x-->', SitemapXmlParser::MAX_BODY_BYTES)
            .'</urlset>';

        $parsed = (new SitemapXmlParser)->parse($raw, 200);

        $this->assertTrue($parsed['body_truncated']);
        $this->assertSame(SitemapXmlParser::MAX_BODY_BYTES, strlen((string) $parsed['body']));
        $this->assertTrue($parsed['malformed']);
    }
}
