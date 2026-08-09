<?php

namespace MoxDop\Website\SeoIntelligence;

use App\Models\DigitalAsset;

/**
 * Normalize Website domain for DataForSEO Labs domain-level targets.
 * Official docs: domain without https:// or www.
 */
final class WebsiteDomainTarget
{
    public static function fromAsset(DigitalAsset $asset): ?string
    {
        $candidates = [
            is_string($asset->domain) ? $asset->domain : null,
            is_string($asset->primary_url) ? $asset->primary_url : null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $host = preg_replace('#^https?://#i', '', $value) ?? '';
            $host = explode('/', $host)[0] ?? '';
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $host = rtrim($host, '.');

        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }
}
