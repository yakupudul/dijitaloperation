<?php

namespace App\Support;

/**
 * Deterministic postal-address candidate extraction/normalization for Evidence.
 */
class WebsitePostalAddressExtractor
{
    /**
     * @return list<array{
     *     street_address: string|null,
     *     locality: string|null,
     *     region: string|null,
     *     postal_code: string|null,
     *     country: string|null,
     *     formatted: string
     * }>
     */
    public function extract(string $html): array
    {
        $candidates = [];

        if (preg_match_all(
            '/<script[^>]*type\s*=\s*[\'"]application\/ld\+json[\'"][^>]*>(.*?)<\/script>/is',
            $html,
            $blocks,
        )) {
            foreach ($blocks[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (! is_array($decoded)) {
                    continue;
                }
                foreach ($this->walkJsonLd($decoded) as $address) {
                    $this->pushCandidate($candidates, $address);
                }
            }
        }

        $microdata = [
            'street_address' => $this->firstItemprop($html, 'streetAddress'),
            'locality' => $this->firstItemprop($html, 'addressLocality'),
            'region' => $this->firstItemprop($html, 'addressRegion'),
            'postal_code' => $this->firstItemprop($html, 'postalCode'),
            'country' => $this->firstItemprop($html, 'addressCountry'),
        ];

        if ($this->hasAnyAddressPart($microdata)) {
            $this->pushCandidate($candidates, $microdata);
        }

        return array_slice($candidates, 0, 10);
    }

    /**
     * Build a comparable alphanumeric key from structured address parts.
     *
     * @param  array{
     *     street_address?: string|null,
     *     locality?: string|null,
     *     region?: string|null,
     *     postal_code?: string|null,
     *     country?: string|null,
     *     address_lines?: list<string>|null
     * }  $parts
     */
    public function normalizeKey(array $parts): ?string
    {
        $chunks = [];

        if (isset($parts['address_lines']) && is_array($parts['address_lines'])) {
            foreach ($parts['address_lines'] as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $chunks[] = trim($line);
                }
            }
        }

        foreach (['street_address', 'locality', 'region', 'postal_code', 'country'] as $key) {
            $value = $parts[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $chunks[] = trim($value);
            }
        }

        if ($chunks === []) {
            return null;
        }

        $joined = strtolower(implode(' ', $chunks));
        $compact = preg_replace('/[^a-z0-9]+/', '', $joined);

        if (! is_string($compact) || strlen($compact) < 6) {
            return null;
        }

        return $compact;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{
     *     street_address: string|null,
     *     locality: string|null,
     *     region: string|null,
     *     postal_code: string|null,
     *     country: string|null
     * }  $parts
     */
    private function pushCandidate(array &$candidates, array $parts): void
    {
        $formattedParts = array_values(array_filter([
            $parts['street_address'] ?? null,
            $parts['locality'] ?? null,
            $parts['region'] ?? null,
            $parts['postal_code'] ?? null,
            $parts['country'] ?? null,
        ], fn ($value): bool => is_string($value) && trim($value) !== ''));

        if ($formattedParts === [] || $this->normalizeKey($parts) === null) {
            return;
        }

        $formatted = implode(', ', $formattedParts);

        foreach ($candidates as $existing) {
            if (($existing['formatted'] ?? null) === $formatted) {
                return;
            }
        }

        $candidates[] = [
            'street_address' => $this->nullableString($parts['street_address'] ?? null),
            'locality' => $this->nullableString($parts['locality'] ?? null),
            'region' => $this->nullableString($parts['region'] ?? null),
            'postal_code' => $this->nullableString($parts['postal_code'] ?? null),
            'country' => $this->nullableString($parts['country'] ?? null),
            'formatted' => $formatted,
        ];
    }

    /**
     * @return list<array{
     *     street_address: string|null,
     *     locality: string|null,
     *     region: string|null,
     *     postal_code: string|null,
     *     country: string|null
     * }>
     */
    private function walkJsonLd(array $node): array
    {
        $found = [];

        if ($this->isPostalAddressNode($node)) {
            $found[] = $this->mapPostalAddressNode($node);
        }

        foreach (['address', '@graph'] as $key) {
            if (! isset($node[$key])) {
                continue;
            }
            $child = $node[$key];
            if (is_array($child) && array_is_list($child)) {
                foreach ($child as $item) {
                    if (is_array($item)) {
                        $found = array_merge($found, $this->walkJsonLd($item));
                    }
                }
            } elseif (is_array($child)) {
                $found = array_merge($found, $this->walkJsonLd($child));
            }
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (is_array($item)) {
                    $found = array_merge($found, $this->walkJsonLd($item));
                }
            }
        }

        return $found;
    }

    private function isPostalAddressNode(array $node): bool
    {
        $type = $node['@type'] ?? null;

        if (is_string($type)) {
            return strcasecmp($type, 'PostalAddress') === 0;
        }

        if (is_array($type)) {
            foreach ($type as $item) {
                if (is_string($item) && strcasecmp($item, 'PostalAddress') === 0) {
                    return true;
                }
            }
        }

        return isset($node['streetAddress']) || isset($node['addressLocality']) || isset($node['postalCode']);
    }

    /**
     * @return array{
     *     street_address: string|null,
     *     locality: string|null,
     *     region: string|null,
     *     postal_code: string|null,
     *     country: string|null
     * }
     */
    private function mapPostalAddressNode(array $node): array
    {
        $country = $node['addressCountry'] ?? null;
        if (is_array($country)) {
            $country = $country['name'] ?? $country['@value'] ?? null;
        }

        return [
            'street_address' => $this->nullableString($node['streetAddress'] ?? null),
            'locality' => $this->nullableString($node['addressLocality'] ?? null),
            'region' => $this->nullableString($node['addressRegion'] ?? null),
            'postal_code' => $this->nullableString($node['postalCode'] ?? null),
            'country' => $this->nullableString(is_string($country) ? $country : null),
        ];
    }

    private function firstItemprop(string $html, string $prop): ?string
    {
        $pattern = '/itemprop\s*=\s*[\'"]'.preg_quote($prop, '/').'[\'"][^>]*>\s*([^<]{2,120})\s*</i';

        if (preg_match($pattern, $html, $match) !== 1) {
            return null;
        }

        return $this->nullableString($match[1]);
    }

    /**
     * @param  array<string, string|null>  $parts
     */
    private function hasAnyAddressPart(array $parts): bool
    {
        foreach ($parts as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $trimmed === '' ? null : $trimmed;
    }
}
