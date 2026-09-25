<?php

namespace App\Services\SeoTasks;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use DOMDocument;
use DOMXPath;
use MoxDop\Website\Standards\StoredPageReader;
use MoxDop\Website\Standards\StoredSeoInspector;
use Throwable;

/**
 * Thin adapter over the Website module's verified stored-HTML reader. Reads already collected
 * snapshots only (no HTTP) and returns the few facts the SEO task rules need.
 */
final class SeoStoredHtmlReader
{
    public function __construct(private readonly StoredPageReader $reader) {}

    /** Verified stored HTML of the page's latest crawl snapshot, or null (Hizmet Beyni page facts read it here). */
    public function html(DigitalAsset $site, WebsitePageProfile $profile): ?string
    {
        try {
            return $this->reader->html($site, $profile);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{title_count: int, description_count: int, h1_count: int, h1_texts: list<string>, images_total: int, images_missing_alt: int, jsonld_types: list<string>, same_as: list<string>, text_excerpt: string, lead_words: ?int, tel_numbers: list<string>}|null
     */
    public function inspect(DigitalAsset $site, WebsitePageProfile $profile, int $excerptChars = 0): ?array
    {
        try {
            $html = $this->reader->html($site, $profile);
        } catch (Throwable) {
            return null;
        }
        if ($html === null) {
            return null;
        }

        $inspection = (new StoredSeoInspector)->inspect($profile->preferred_url, $html);
        if ($inspection === null) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);

        $h1Texts = [];
        foreach ($xpath->query('//h1') ?: [] as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            if ($text !== '') {
                $h1Texts[] = mb_substr($text, 0, 160);
            }
        }

        $types = [];
        $sameAs = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode(trim((string) $node->textContent), true);
            if (is_array($decoded)) {
                $this->walkJsonLd($decoded, $types, $sameAs);
            }
        }

        // Answer block (GEO/AEO): the first real paragraph after the H1. Null when the page has no H1.
        $leadWords = null;
        if ($xpath->query('//h1')?->length) {
            $lead = $xpath->query('(//h1)[1]/following::p[string-length(normalize-space()) > 40][1]')?->item(0);
            $leadWords = $lead !== null ? count(preg_split('/\s+/u', trim((string) $lead->textContent)) ?: []) : 0;
        }

        $tel = [];
        foreach ($xpath->query('//a[starts-with(translate(@href, "TEL", "tel"), "tel:")]') ?: [] as $node) {
            $digits = preg_replace('/\D+/', '', (string) $node->getAttribute('href')) ?? '';
            if (strlen($digits) >= 7) {
                $tel[] = substr($digits, -10);
            }
        }

        $excerpt = '';
        if ($excerptChars > 0) {
            foreach ($xpath->query('//script|//style|//noscript|//svg') ?: [] as $node) {
                $node->parentNode?->removeChild($node);
            }
            $body = $xpath->query('//body')?->item(0);
            $text = trim(preg_replace('/\s+/u', ' ', (string) ($body?->textContent ?? '')) ?? '');
            $excerpt = mb_substr($text, 0, $excerptChars);
        }

        return [
            'title_count' => (int) ($inspection['title_count'] ?? 0),
            'description_count' => (int) ($inspection['description_count'] ?? 0),
            'h1_count' => (int) $inspection['h1_count'],
            'h1_texts' => array_slice($h1Texts, 0, 5),
            'images_total' => (int) ($inspection['images']['total'] ?? 0),
            'images_missing_alt' => (int) ($inspection['images']['missing_alt'] ?? 0),
            'jsonld_types' => array_values(array_unique($types)),
            'same_as' => array_values(array_unique($sameAs)),
            'text_excerpt' => $excerpt,
            'lead_words' => $leadWords,
            'tel_numbers' => array_values(array_unique($tel)),
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $types
     * @param  list<string>  $sameAs
     */
    private function walkJsonLd(array $node, array &$types, array &$sameAs, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }
        if (isset($node['sameAs'])) {
            foreach ((array) $node['sameAs'] as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $sameAs[] = $url;
                }
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value) && $key !== 'sameAs') {
                $this->walkJsonLd($value, $types, $sameAs, $depth + 1);
            }
        }
    }
}
