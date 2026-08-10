<?php

namespace MoxDop\Website\Discovery;

use InvalidArgumentException;

/**
 * SSRF-safe URL validation for public Website Discovery.
 *
 * Validates scheme, host, and resolved destination IPs before request.
 * Callers must re-validate every redirect destination.
 */
final class PublicUrlSafety
{
    /**
     * @param  (callable(string): list<string>)|null  $dnsResolver
     */
    public function __construct(
        private $dnsResolver = null,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function assertSafePublicHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('URL is required.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('URL could not be parsed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs are allowed.');
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            throw new InvalidArgumentException('URL host is required.');
        }
        $host = trim($host, '[]');

        if ($this->isBlockedHostname($host)) {
            throw new InvalidArgumentException('Internal or private hostnames are not allowed.');
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new InvalidArgumentException('Host could not be resolved to a public address.');
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new InvalidArgumentException('Resolved destination is not a public address.');
            }
        }

        return $url;
    }

    public function isBlockedHostname(string $host): bool
    {
        $host = strtolower(trim($host));
        $host = trim($host, '[]');
        $host = rtrim($host, '.');

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if ($host === 'metadata.google.internal' || str_ends_with($host, '.internal')) {
            return true;
        }

        // Literal IP hostnames.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host);
        }

        return false;
    }

    public function isBlockedIp(string $ip): bool
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);

            if ($normalized === '::1' || str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd')) {
                return true;
            }

            if (str_starts_with($normalized, 'fe80:')) {
                return true;
            }

            // IPv4-mapped IPv6.
            if (str_starts_with($normalized, '::ffff:')) {
                $v4 = substr($normalized, 7);

                return $this->isBlockedIp($v4);
            }

            return ! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (is_callable($this->dnsResolver)) {
            /** @var list<string> $resolved */
            $resolved = ($this->dnsResolver)($host);

            return array_values(array_unique(array_filter($resolved, 'is_string')));
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $fallback = @gethostbynamel($host);
            if (! is_array($fallback)) {
                return [];
            }

            return array_values(array_unique(array_filter($fallback, 'is_string')));
        }

        $ips = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
