<?php

namespace Tests\Unit;

use InvalidArgumentException;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use MoxDop\Website\Discovery\PublicUrlSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicUrlSafetyTest extends TestCase
{
    #[Test]
    #[DataProvider('blockedUrls')]
    public function it_rejects_unsafe_urls(string $url): void
    {
        $safety = new PublicUrlSafety(fn (string $host): array => match ($host) {
            'evil.example' => ['127.0.0.1'],
            'metadata.example' => ['169.254.169.254'],
            'private.example' => ['10.0.0.8'],
            default => ['93.184.216.34'],
        });

        $this->expectException(InvalidArgumentException::class);
        $safety->assertSafePublicHttpUrl($url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'localhost' => ['http://localhost/'],
            '127' => ['http://127.0.0.1/'],
            'ipv6_loopback' => ['http://[::1]/'],
            'rfc1918' => ['http://10.0.0.5/'],
            'link_local' => ['http://169.254.1.1/'],
            'metadata_ip' => ['http://169.254.169.254/'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/'],
            'gopher' => ['gopher://example.com/'],
            'dns_to_loopback' => ['https://evil.example/'],
            'dns_to_metadata' => ['https://metadata.example/latest'],
            'dns_to_private' => ['https://private.example/'],
        ];
    }

    #[Test]
    public function it_allows_public_http_and_https_literal_ips(): void
    {
        $safety = new PublicUrlSafety;

        $this->assertSame('http://1.1.1.1/', $safety->assertSafePublicHttpUrl('http://1.1.1.1/'));
        $this->assertSame('https://8.8.8.8/path', $safety->assertSafePublicHttpUrl('https://8.8.8.8/path'));
    }

    #[Test]
    public function normalizer_enforces_same_site_and_dedupes(): void
    {
        $normalizer = new PublicUrlNormalizer;

        $a = $normalizer->normalizeAbsolute('https://WWW.Example.com/About/#top');
        $b = $normalizer->normalizeAbsolute('https://example.com/about');
        $this->assertSame($a, $b);
        $this->assertTrue($normalizer->sameSite('https://example.com/', 'https://www.example.com/services'));
        $this->assertFalse($normalizer->sameSite('https://example.com/', 'https://other.com/'));
        $this->assertSame(
            'https://example.com/contact',
            $normalizer->resolve('https://example.com/about', '/contact'),
        );
    }
}
