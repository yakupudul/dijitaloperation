<?php

namespace MoxDop\Website\Standards;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MoxDop\Website\Discovery\PublicUrlNormalizer;

final class StoredSeoInspector
{
    /** One bounded parse, no HTTP, JavaScript or external entity loading. */
    public function inspect(string $url, string $html): ?array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            return null;
        }
        $xpath = new DOMXPath($document);
        $urls = new PublicUrlNormalizer;
        $base = $url;
        $baseNode = $xpath->query('//head/base[@href]')?->item(0);
        if ($baseNode instanceof DOMElement) {
            $base = $urls->resolve($url, $baseNode->getAttribute('href')) ?? $url;
        }
        $links = [];
        $truncated = false;
        $emptyAnchors = 0;
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $href = $urls->resolve($base, $node->getAttribute('href'));
            if ($href === null || parse_url($href, PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST)) {
                continue;
            }
            if (count($links) >= 500) {
                $truncated = true;
                break;
            }
            $links[$href] = $href;
            if (trim($node->textContent) === '' && trim($node->getAttribute('aria-label')) === ''
                && ! $node->hasAttribute('aria-labelledby') && $node->getElementsByTagName('img')->length === 0) {
                $emptyAnchors++;
            }
        }
        $images = ['total' => 0, 'missing_alt' => 0, 'missing_dimensions' => 0, 'truncated' => false];
        foreach ($xpath->query('//img') ?: [] as $node) {
            if ($images['total'] >= 1000) {
                $images['truncated'] = true;
                break;
            }
            $images['total']++;
            $images['missing_alt'] += $node->hasAttribute('alt') ? 0 : 1;
            $images['missing_dimensions'] += $node->hasAttribute('width') && $node->hasAttribute('height') ? 0 : 1;
        }
        $mixed = 0;
        if (parse_url($url, PHP_URL_SCHEME) === 'https') {
            foreach ($xpath->query('//script[@src]|//img[@src]|//iframe[@src]|//link[@href]') ?: [] as $node) {
                $attribute = $node->tagName === 'link' ? 'href' : 'src';
                if ($node->tagName === 'link' && strtolower($node->getAttribute('rel')) !== 'stylesheet') {
                    continue;
                }
                $mixed += stripos(trim($node->getAttribute($attribute)), 'http://') === 0 ? 1 : 0;
            }
        }
        $hreflang = [];
        $hreflangTruncated = false;
        foreach ($xpath->query('//head/link[@hreflang][@href]') ?: [] as $node) {
            if (count($hreflang) >= 100) {
                $hreflangTruncated = true;
                break;
            }
            $hreflang[] = ['language' => $node->getAttribute('hreflang'), 'url' => $urls->resolve($base, $node->getAttribute('href'))];
        }
        return [
            'base_url' => $base,
            'title_count' => $xpath->query('//head/title')->length,
            'h1_count' => $xpath->query('//h1')->length,
            'description_count' => $xpath->query('//head/meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]')->length,
            'language' => trim((string) $document->documentElement?->getAttribute('lang')),
            'internal_urls' => array_values($links), 'links_truncated' => $truncated,
            'empty_anchors' => $emptyAnchors, 'images' => $images, 'mixed_resources' => $mixed,
            'hreflang' => $hreflang, 'hreflang_truncated' => $hreflangTruncated,
        ];
    }
}
