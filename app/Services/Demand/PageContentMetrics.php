<?php

namespace App\Services\Demand;

use App\Services\SeoTasks\SeoText;
use DOMDocument;
use DOMXPath;

/**
 * Comparable, rule-friendly facts about one HTML page (pure function, no I/O): visible word count, headings,
 * FAQ presence, structured-data types, price mentions, place mentions and internal links.
 */
final class PageContentMetrics
{
    /**
     * @param  list<string>  $places  folded place names to look for (brand service areas)
     * @return array{words: int, title: string, h1: string, h2_count: int, h2: list<string>, faq: bool, schema_types: list<string>, has_price: bool, mentions_place: bool, text_folded: string, internal_links: int, images: int}
     */
    public static function from(string $url, string $html, array $places = []): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);

        $schemaTypes = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode((string) $node->textContent, true);
            if (! is_array($decoded)) {
                continue;
            }
            array_walk_recursive($decoded, function ($value, $key) use (&$schemaTypes): void {
                if ($key === '@type' && is_string($value)) {
                    $schemaTypes[$value] = true;
                }
            });
        }
        foreach ($xpath->query('//script|//style|//noscript|//template|//svg') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) ($document->getElementsByTagName('body')->item(0)?->textContent ?? '')) ?? '');
        $headings = static fn (string $tag): array => array_values(array_filter(array_map(
            static fn ($node): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? ''), 0, 160),
            iterator_to_array($xpath->query('//'.$tag) ?: []),
        )));
        $h2 = $headings('h2');
        $questionHeadings = count(array_filter(array_merge($h2, $headings('h3')), static fn (string $h): bool => str_ends_with($h, '?')));

        $host = (string) parse_url($url, PHP_URL_HOST);
        $internal = 0;
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            $href = (string) $link->getAttribute('href');
            $linkHost = (string) parse_url($href, PHP_URL_HOST);
            if ($href !== '' && ! str_starts_with($href, '#') && ($linkHost === '' || $linkHost === $host)) {
                $internal++;
            }
        }

        $folded = ' '.SeoText::fold($text).' ';
        $mentionsPlace = false;
        foreach ($places as $place) {
            if ($place !== '' && str_contains($folded, ' '.$place.' ')) {
                $mentionsPlace = true;
                break;
            }
        }
        $titleNode = $xpath->query('//title')->item(0);

        return [
            'words' => $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []),
            'title' => mb_substr(trim((string) $titleNode?->textContent), 0, 200),
            'h1' => $headings('h1')[0] ?? '',
            'h2_count' => count($h2),
            'h2' => array_slice($h2, 0, 30),
            'faq' => isset($schemaTypes['FAQPage']) || $questionHeadings >= 3,
            'schema_types' => array_keys($schemaTypes),
            'has_price' => preg_match('/\b(fiyat|ucret)[a-z]*\b|\btl\b/u', $folded) === 1 || str_contains($text, '₺'),
            'mentions_place' => $mentionsPlace,
            // Folded text excerpt so place mentions can be evaluated per brand from a shared page cache.
            'text_folded' => mb_substr(trim($folded), 0, 20000),
            'internal_links' => $internal,
            'images' => $xpath->query('//img')->length,
        ];
    }
}
