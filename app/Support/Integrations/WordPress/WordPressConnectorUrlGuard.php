<?php

namespace App\Support\Integrations\WordPress;

use App\Models\DigitalAsset;
use InvalidArgumentException;

final class WordPressConnectorUrlGuard
{
    /**
     * @param  list<string>  $candidateUrls
     */
    public function assertMatchesAsset(DigitalAsset $asset, array $candidateUrls): void
    {
        $assetHosts = array_values(array_unique(array_filter([
            $this->host((string) $asset->primary_url),
            $this->host((string) $asset->domain),
        ])));

        if ($assetHosts === []) {
            throw new InvalidArgumentException('Website Digital Asset has no valid host.');
        }

        foreach ($candidateUrls as $candidateUrl) {
            $candidateHost = $this->host($candidateUrl);
            if ($candidateHost === null || ! in_array($candidateHost, $assetHosts, true)) {
                throw new InvalidArgumentException('WordPress connector site host does not match the Website Digital Asset.');
            }
        }
    }

    public function assertHttpsEndpoint(string $url): void
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '') {
            throw new InvalidArgumentException('WordPress connector endpoint must be an absolute HTTPS URL.');
        }
    }

    public function assertConnectorEndpoint(string $url, string $operation): void
    {
        $path = rtrim((string) parse_url(trim($url), PHP_URL_PATH), '/');
        $expected = '/wp-json/moxdop/v1/'.trim($operation, '/');
        if (! str_ends_with($path, $expected)) {
            throw new InvalidArgumentException('WordPress connector endpoint path is invalid.');
        }
    }

    private function host(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower(trim($host))) ?: null;
    }
}
