<?php

namespace Tests\Unit;

use MoxDop\Website\Diagnosis\DocumentHeadParser;
use PHPUnit\Framework\TestCase;

class DocumentHeadParserTest extends TestCase
{
    public function test_parses_title_charset_viewport_description_robots_and_json_ld(): void
    {
        $html = <<<'HTML'
        <!doctype html>
        <html><head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Example Title</title>
            <meta name="description" content="A solid description for the page.">
            <meta name="robots" content="index, follow">
            <meta property="og:title" content="OG Title">
            <meta property="og:description" content="OG Description">
            <meta property="og:image" content="https://example.com/og.png">
            <script type="application/ld+json">{"@type":"Organization","name":"Example"}</script>
        </head><body></body></html>
        HTML;

        $parsed = (new DocumentHeadParser)->parse($html);

        $this->assertTrue($parsed['title_present']);
        $this->assertFalse($parsed['title_empty']);
        $this->assertSame('Example Title', $parsed['title']);
        $this->assertTrue($parsed['charset_present']);
        $this->assertSame('utf-8', $parsed['charset']);
        $this->assertTrue($parsed['viewport_present']);
        $this->assertTrue($parsed['meta_description_present']);
        $this->assertFalse($parsed['meta_description_empty']);
        $this->assertSame(['index', 'follow'], $parsed['robots_directives']);
        $this->assertSame(1, $parsed['json_ld']['block_count']);
        $this->assertSame(1, $parsed['json_ld']['parse_ok_count']);
        $this->assertSame(0, $parsed['json_ld']['malformed_count']);
        $this->assertContains('Organization', $parsed['json_ld']['types']);
        $this->assertSame(3, $parsed['open_graph_present_count']);
    }

    public function test_detects_malformed_json_ld_and_noindex(): void
    {
        $html = <<<'HTML'
        <html><head>
            <title>x</title>
            <meta name="googlebot" content="noindex">
            <script type="application/ld+json">{not-json</script>
        </head><body></body></html>
        HTML;

        $parsed = (new DocumentHeadParser)->parse($html);

        $this->assertContains('noindex', $parsed['googlebot_directives']);
        $this->assertSame(1, $parsed['json_ld']['malformed_count']);
        $this->assertSame(0, $parsed['json_ld']['parse_ok_count']);
    }
}
