<?php

namespace Tests\Unit;

use App\Services\Collection\Providers\Website\WebsitePageAnalyzer;
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
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

    public function test_url_normalization_preserves_server_resource_identity(): void
    {
        $urls = new PublicUrlNormalizer;

        self::assertSame(
            'https://www.example.com/Products/ABC/?Sku=AbC',
            $urls->normalizeAbsolute('HTTPS://WWW.Example.COM/Products/ABC/?Sku=AbC#details'),
        );
        self::assertSame('https://example.com/Products/ABC', $urls->normalizeAbsolute('example.com/Products/ABC'));
        self::assertSame('https://example.com/Products/ABC/', $urls->normalizeAbsolute('example.com/Products/ABC/'));
        self::assertNotSame(
            $urls->normalizeAbsolute('https://example.com/Products/ABC'),
            $urls->normalizeAbsolute('https://example.com/products/abc'),
        );
        self::assertNotSame(
            $urls->normalizeAbsolute('https://example.com/Products/ABC'),
            $urls->normalizeAbsolute('https://example.com/Products/ABC/'),
        );
    }

    public function test_url_normalization_rejects_non_http_schemes(): void
    {
        $urls = new PublicUrlNormalizer;

        self::assertNull($urls->normalizeAbsolute('mailto:test@example.com'));
        self::assertNull($urls->normalizeAbsolute('tel:+905551112233'));
        self::assertNull($urls->normalizeAbsolute('javascript:alert(1)'));
    }

    public function test_relative_url_resolution_uses_browser_document_semantics(): void
    {
        $urls = new PublicUrlNormalizer;

        self::assertSame(
            'https://www.example.com/Catalog/NextPage',
            $urls->resolve('https://www.example.com/Catalog/Item', 'NextPage'),
        );
        self::assertSame(
            'https://www.example.com/Catalog/Item/NextPage',
            $urls->resolve('https://www.example.com/Catalog/Item/', 'NextPage'),
        );
        self::assertSame(
            'https://www.example.com/Catalog/Other/',
            $urls->resolve('https://www.example.com/Catalog/Item/', '../Other/'),
        );
    }

    public function test_query_only_relative_url_stays_on_current_document(): void
    {
        $urls = new PublicUrlNormalizer;

        self::assertSame(
            'https://www.example.com/Products/ABC?page=2',
            $urls->resolve('https://www.example.com/Products/ABC?sort=name', '?page=2#results'),
        );
    }

    public function test_www_and_apex_hosts_are_same_site_without_rewriting_fetch_target(): void
    {
        $urls = new PublicUrlNormalizer;

        self::assertTrue($urls->sameSite('https://www.example.com/Products', 'https://example.com/products'));
        self::assertSame(
            'https://www.example.com/Products',
            $urls->normalizeAbsolute('https://www.example.com/Products'),
        );
    }

    public function test_external_redirect_is_not_promoted_to_website_inventory(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = $this->htmlFetch(
            'https://example.com/outbound',
            '<!doctype html><html><head><title>External destination</title></head><body><h1>External destination</h1></body></html>',
        );
        $fetch['final_url'] = 'https://unrelated.example.org/landing';
        $fetch['redirect_count'] = 1;

        self::assertFalse($analyzer->isInventoryEligible($fetch));

        $issues = $analyzer->issueSnapshots(42, $fetch, '2026-08-29 12:00:00');
        $issue = collect($issues)->firstWhere('issue_code', 'EXTERNAL_REDIRECT');

        self::assertIsArray($issue);
        self::assertSame('medium', $issue['severity']);
        self::assertSame('https://example.com/outbound', $issue['url']);
        self::assertSame('https://unrelated.example.org/landing', $issue['metadata']['evidence']['final_url']);
        self::assertNull(collect($issues)->firstWhere('issue_code', 'CANONICAL_MISSING'));
    }

    public function test_www_to_apex_redirect_remains_inventory_eligible(): void
    {
        $analyzer = new WebsitePageAnalyzer;
        $fetch = $this->htmlFetch(
            'https://www.example.com/services',
            '<!doctype html><html><head><title>Services</title><meta name="description" content="Services"><link rel="canonical" href="https://example.com/services"></head><body><h1>Services</h1></body></html>',
        );
        $fetch['final_url'] = 'https://example.com/services';
        $fetch['redirect_count'] = 1;

        self::assertTrue($analyzer->isInventoryEligible($fetch));
        self::assertNull(collect($analyzer->issueSnapshots(1, $fetch, '2026-08-29 12:00:00'))->firstWhere('issue_code', 'EXTERNAL_REDIRECT'));
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
