<?php

namespace Tests\Unit;

use App\Services\Collection\Providers\Website\WebsitePageAnalyzer;
use MoxDop\Website\Discovery\DiscoveryConfig;
use PHPUnit\Framework\TestCase;

final class WebsiteDiscoveryTruthfulnessTest extends TestCase
{
    public function test_production_discovery_does_not_guess_common_page_paths(): void
    {
        self::assertSame(['/'], DiscoveryConfig::preferredPathHints());
        self::assertSame(
            ['/sitemap.xml', '/sitemap_index.xml', '/sitemaps.xml'],
            DiscoveryConfig::sitemapFallbackPaths(),
        );
        self::assertNotContains('/contact-us', DiscoveryConfig::preferredPathHints());
        self::assertNotContains('/location', DiscoveryConfig::preferredPathHints());
        self::assertNotContains('/locations', DiscoveryConfig::preferredPathHints());
    }

    public function test_wordpress_critical_error_template_is_not_promoted_to_page_inventory(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = $this->htmlFetch(
            'https://www.moximu.com/contact-us',
            '<!doctype html><html><head><title>WordPress Error</title></head><body><p>There has been a critical error on this website.</p></body></html>',
        );

        self::assertFalse($analyzer->isInventoryEligible($fetch));

        $issues = $analyzer->issueSnapshots(1, $fetch, '2026-08-29 12:00:00');
        self::assertContains('INVALID_PAGE_RESPONSE', array_column($issues, 'issue_code'));
    }

    public function test_soft_404_template_is_not_promoted_to_page_inventory(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = $this->htmlFetch(
            'https://example.com/does-not-exist',
            '<!doctype html><html><head><title>404 Not Found</title></head><body><h1>Page not found</h1></body></html>',
        );

        self::assertFalse($analyzer->isInventoryEligible($fetch));
    }

    public function test_real_successful_html_page_is_inventory_eligible(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = $this->htmlFetch(
            'https://example.com/services',
            '<!doctype html><html><head><title>Services</title><meta name="description" content="Services"><link rel="canonical" href="https://example.com/services"></head><body><h1>Services</h1><p>Real page content.</p></body></html>',
        );

        self::assertTrue($analyzer->isInventoryEligible($fetch));
    }

    /** @return array<string, mixed> */
    private function htmlFetch(string $url, string $body): array
    {
        return [
            'ok' => true,
            'requested_url' => $url,
            'final_url' => $url,
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => $body,
            'bytes' => strlen($body),
            'redirect_count' => 0,
            'error' => null,
        ];
    }
}
