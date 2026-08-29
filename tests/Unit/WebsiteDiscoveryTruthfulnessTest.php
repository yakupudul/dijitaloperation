<?php

namespace Tests\Unit;

use App\Services\Collection\Providers\Website\WebsitePageAnalyzer;
use MoxDop\Website\Discovery\DiscoveryConfig;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('applicationErrorProvider')]
    public function test_application_error_templates_are_not_promoted_to_page_inventory(
        string $body,
        string $expectedIssueCode,
        string $expectedSeverity,
    ): void {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = $this->htmlFetch('https://example.com/broken', $body);

        self::assertFalse($analyzer->isInventoryEligible($fetch));

        $issues = $analyzer->issueSnapshots(1, $fetch, '2026-08-29 12:00:00');
        $issue = collect($issues)->firstWhere('issue_code', $expectedIssueCode);

        self::assertIsArray($issue);
        self::assertSame($expectedSeverity, $issue['severity']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function applicationErrorProvider(): array
    {
        return [
            'wordpress critical english' => [
                '<!doctype html><html><head><title>WordPress Error</title></head><body>There has been a critical error on this website.</body></html>',
                'WORDPRESS_CRITICAL_ERROR',
                'critical',
            ],
            'wordpress critical turkish' => [
                '<!doctype html><html><head><title>Hata</title></head><body>Sitenizde ciddi bir sorun çıktı.</body></html>',
                'WORDPRESS_CRITICAL_ERROR',
                'critical',
            ],
            'wordpress database error' => [
                '<!doctype html><html><head><title>Error</title></head><body>Error establishing a database connection</body></html>',
                'WORDPRESS_DATABASE_ERROR',
                'critical',
            ],
            'maintenance application error' => [
                '<!doctype html><html><head><title>Maintenance</title></head><body>Briefly unavailable for scheduled maintenance. Check back in a minute.</body></html>',
                'APPLICATION_ERROR_PAGE',
                'high',
            ],
            'soft 404' => [
                '<!doctype html><html><head><title>404 Not Found</title></head><body><h1>Page not found</h1></body></html>',
                'SOFT_404',
                'high',
            ],
        ];
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
