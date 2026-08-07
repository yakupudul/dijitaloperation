<?php

namespace Tests\Unit;

use App\Support\CanonicalLinkParser;
use PHPUnit\Framework\TestCase;

class CanonicalLinkParserTest extends TestCase
{
    public function test_extracts_single_absolute_canonical_from_head(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <title>Example</title>
  <link rel="canonical" href="https://example.com/page">
</head>
<body>hi</body>
</html>
HTML;

        $parsed = (new CanonicalLinkParser)->parse($html);

        $this->assertTrue($parsed['head_complete']);
        $this->assertFalse($parsed['head_truncated']);
        $this->assertSame(['https://example.com/page'], $parsed['canonical_hrefs']);
        $this->assertSame(['https://example.com/page'], $parsed['absolute_canonical_hrefs']);
        $this->assertSame([], $parsed['relative_canonical_hrefs']);
    }

    public function test_rel_token_list_supports_canonical(): void
    {
        $html = '<html><head><link rel="canonical prefetch" href="https://example.com/"></head><body></body></html>';

        $parsed = (new CanonicalLinkParser)->parse($html);

        $this->assertSame(['https://example.com/'], $parsed['absolute_canonical_hrefs']);
    }

    public function test_relative_canonical_is_classified_separately(): void
    {
        $html = '<html><head><link rel="canonical" href="/page"></head><body></body></html>';

        $parsed = (new CanonicalLinkParser)->parse($html);

        $this->assertSame(['/page'], $parsed['canonical_hrefs']);
        $this->assertSame([], $parsed['absolute_canonical_hrefs']);
        $this->assertSame(['/page'], $parsed['relative_canonical_hrefs']);
    }

    public function test_multiple_absolute_canonicals_are_returned(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="canonical" href="https://a.example/">
<link rel="canonical" href="https://b.example/">
</head><body></body></html>
HTML;

        $parsed = (new CanonicalLinkParser)->parse($html);

        $this->assertSame(
            ['https://a.example/', 'https://b.example/'],
            $parsed['absolute_canonical_hrefs'],
        );
    }

    public function test_missing_canonical_returns_empty_lists(): void
    {
        $html = '<html><head><title>No canonical</title></head><body></body></html>';

        $parsed = (new CanonicalLinkParser)->parse($html);

        $this->assertSame([], $parsed['canonical_hrefs']);
        $this->assertTrue($parsed['head_complete']);
    }

    public function test_is_absolute_http_url(): void
    {
        $parser = new CanonicalLinkParser;

        $this->assertTrue($parser->isAbsoluteHttpUrl('https://example.com/x'));
        $this->assertTrue($parser->isAbsoluteHttpUrl('http://example.com/x'));
        $this->assertFalse($parser->isAbsoluteHttpUrl('/x'));
        $this->assertFalse($parser->isAbsoluteHttpUrl('//example.com/x'));
        $this->assertFalse($parser->isAbsoluteHttpUrl('ftp://example.com/x'));
    }
}
