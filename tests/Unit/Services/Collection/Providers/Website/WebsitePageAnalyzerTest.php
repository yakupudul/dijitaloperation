<?php

namespace Tests\Unit\Services\Collection\Providers\Website;

use App\Services\Collection\Providers\Website\WebsitePageAnalyzer;
use PHPUnit\Framework\TestCase;

final class WebsitePageAnalyzerTest extends TestCase
{
    public function test_it_builds_content_stats_and_link_graph_without_fabricating_scores(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $html = <<<'HTML'
<!doctype html>
<html lang="tr">
<head>
<title>İmplant Tedavisi</title>
<meta name="description" content="Tedavi bilgileri">
<link rel="canonical" href="https://example.com/implant/">
</head>
<body>
<h1>İmplant Tedavisi</h1>
<h2>Kimler için uygundur?</h2>
<p>Detaylı tedavi içeriği burada yer alır.</p>
<a href="/implant/fiyatlar/?utm_source=test">Fiyat bilgileri</a>
<a href="https://external.example.org/source" rel="nofollow">Kaynak</a>
</body>
</html>
HTML;
        $fetch = [
            'ok' => true,
            'requested_url' => 'https://example.com/implant/',
            'final_url' => 'https://example.com/implant/',
            'status_code' => 200,
            'content_type' => 'text/html; charset=UTF-8',
            'redirect_count' => 0,
            'body' => $html,
        ];

        $stats = $analyzer->contentStats(7, $fetch, '2026-08-29T09:00:00Z');
        self::assertNotNull($stats);
        self::assertSame('tr', $stats['metadata']['language']);
        self::assertSame(1, $stats['metadata']['h1_count']);
        self::assertSame(1, $stats['metadata']['h2_count']);
        self::assertArrayNotHasKey('seo_score', $stats['metadata']);

        $edges = $analyzer->linkEdges(7, $html, 'https://example.com/', 'https://example.com/implant/', '2026-08-29T09:00:00Z');
        self::assertCount(2, $edges);
        self::assertTrue($edges[0]['is_internal']);
        self::assertFalse($edges[1]['is_internal']);
        self::assertTrue($edges[1]['nofollow']);
    }

    public function test_it_emits_only_deterministic_issue_codes_supported_by_page_evidence(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = [
            'ok' => true,
            'requested_url' => 'https://example.com/broken-page/',
            'final_url' => 'https://example.com/broken-page/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'redirect_count' => 2,
            'body' => '<html><head><meta name="robots" content="noindex"></head><body><h1>A</h1><h1>B</h1></body></html>',
        ];

        $codes = array_column($analyzer->issueSnapshots(7, $fetch, '2026-08-29T09:00:00Z'), 'issue_code');

        self::assertContains('REDIRECT_CHAIN', $codes);
        self::assertContains('MISSING_TITLE', $codes);
        self::assertContains('MISSING_META_DESCRIPTION', $codes);
        self::assertContains('MULTIPLE_H1', $codes);
        self::assertContains('CANONICAL_MISSING', $codes);
        self::assertContains('NOINDEX', $codes);
    }

    public function test_non_html_responses_do_not_receive_html_seo_issues(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = [
            'ok' => true,
            'requested_url' => 'https://example.com/file.pdf',
            'final_url' => 'https://example.com/file.pdf',
            'status_code' => 200,
            'content_type' => 'application/pdf',
            'redirect_count' => 0,
            'body' => '%PDF-1.7',
        ];

        self::assertNull($analyzer->contentStats(7, $fetch, '2026-08-29T09:00:00Z'));
        self::assertSame([], $analyzer->issueSnapshots(7, $fetch, '2026-08-29T09:00:00Z'));
    }
}
