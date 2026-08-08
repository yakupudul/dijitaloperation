<?php

namespace MoxDop\Website\Diagnosis;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Normalize Document Head signals from HTML for Website Diagnosis Evidence.
 * Stores structured fields only — not uncontrolled full HTML dumps.
 */
final class DocumentHeadParser
{
    /**
     * @return array{
     *     title: string|null,
     *     title_present: bool,
     *     title_empty: bool,
     *     title_length: int|null,
     *     charset: string|null,
     *     charset_present: bool,
     *     viewport: string|null,
     *     viewport_present: bool,
     *     meta_description: string|null,
     *     meta_description_present: bool,
     *     meta_description_empty: bool,
     *     meta_description_length: int|null,
     *     meta_robots: string|null,
     *     meta_googlebot: string|null,
     *     robots_directives: list<string>,
     *     googlebot_directives: list<string>,
     *     hreflang: list<array{hreflang: string, href: string}>,
     *     open_graph: array{title: ?string, description: ?string, image: ?string, url: ?string, type: ?string},
     *     open_graph_present_count: int,
     *     json_ld: array{
     *         block_count: int,
     *         parse_ok_count: int,
     *         malformed_count: int,
     *         types: list<string>
     *     }
     * }
     */
    public function parse(?string $html): array
    {
        $empty = $this->emptyResult();

        if ($html === null || trim($html) === '') {
            return $empty;
        }

        try {
            $dom = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } catch (Throwable) {
            return $empty;
        }

        $xpath = new DOMXPath($dom);

        $titleText = null;
        $titleNodes = $xpath->query('//head//title|//title');
        $titlePresent = $titleNodes !== false && $titleNodes->length > 0;
        if ($titlePresent) {
            $titleText = trim((string) $titleNodes->item(0)?->textContent);
        }

        $charset = $this->extractCharset($xpath);
        $viewport = $this->metaContent($xpath, 'viewport');
        $description = $this->metaContent($xpath, 'description');
        $robots = $this->metaContent($xpath, 'robots');
        $googlebot = $this->metaContent($xpath, 'googlebot');

        $og = [
            'title' => $this->metaProperty($xpath, 'og:title'),
            'description' => $this->metaProperty($xpath, 'og:description'),
            'image' => $this->metaProperty($xpath, 'og:image'),
            'url' => $this->metaProperty($xpath, 'og:url'),
            'type' => $this->metaProperty($xpath, 'og:type'),
        ];
        $ogPresent = count(array_filter($og, static fn (?string $v): bool => $v !== null && $v !== ''));

        return [
            'title' => $titlePresent ? $titleText : null,
            'title_present' => $titlePresent,
            'title_empty' => $titlePresent && ($titleText === null || $titleText === ''),
            'title_length' => $titlePresent && $titleText !== null ? mb_strlen($titleText) : null,
            'charset' => $charset,
            'charset_present' => $charset !== null && $charset !== '',
            'viewport' => $viewport,
            'viewport_present' => $viewport !== null && trim($viewport) !== '',
            'meta_description' => $description,
            'meta_description_present' => $description !== null,
            'meta_description_empty' => $description !== null && trim($description) === '',
            'meta_description_length' => $description !== null ? mb_strlen(trim($description)) : null,
            'meta_robots' => $robots,
            'meta_googlebot' => $googlebot,
            'robots_directives' => $this->parseDirectives($robots),
            'googlebot_directives' => $this->parseDirectives($googlebot),
            'hreflang' => $this->extractHreflang($xpath),
            'open_graph' => $og,
            'open_graph_present_count' => $ogPresent,
            'json_ld' => $this->extractJsonLd($xpath),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'title' => null,
            'title_present' => false,
            'title_empty' => false,
            'title_length' => null,
            'charset' => null,
            'charset_present' => false,
            'viewport' => null,
            'viewport_present' => false,
            'meta_description' => null,
            'meta_description_present' => false,
            'meta_description_empty' => false,
            'meta_description_length' => null,
            'meta_robots' => null,
            'meta_googlebot' => null,
            'robots_directives' => [],
            'googlebot_directives' => [],
            'hreflang' => [],
            'open_graph' => [
                'title' => null,
                'description' => null,
                'image' => null,
                'url' => null,
                'type' => null,
            ],
            'open_graph_present_count' => 0,
            'json_ld' => [
                'block_count' => 0,
                'parse_ok_count' => 0,
                'malformed_count' => 0,
                'types' => [],
            ],
        ];
    }

    private function extractCharset(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//head//meta[@charset]|//meta[@charset]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $value = trim($node->getAttribute('charset'));
                    if ($value !== '') {
                        return strtolower($value);
                    }
                }
            }
        }

        $httpEquiv = $xpath->query('//head//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-type"]|//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-type"]');
        if ($httpEquiv !== false) {
            foreach ($httpEquiv as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $content = $node->getAttribute('content');
                if (preg_match('/charset\s*=\s*([^\s;]+)/i', $content, $m) === 1) {
                    return strtolower(trim($m[1], "\"'"));
                }
            }
        }

        return null;
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        $query = sprintf(
            '//head//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"]|//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"]',
            $name,
            $name,
        );
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (! $node instanceof DOMElement) {
            return null;
        }

        return $node->getAttribute('content');
    }

    private function metaProperty(DOMXPath $xpath, string $property): ?string
    {
        $query = sprintf(
            '//head//meta[@property="%s"]|//meta[@property="%s"]',
            $property,
            $property,
        );
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $node = $nodes->item(0);
        if (! $node instanceof DOMElement) {
            return null;
        }
        $value = trim($node->getAttribute('content'));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function parseDirectives(?string $content): array
    {
        if ($content === null || trim($content) === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', strtolower(trim($content))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{hreflang: string, href: string}>
     */
    private function extractHreflang(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//head//link[@hreflang]|//link[@hreflang]');
        $out = [];
        if ($nodes === false) {
            return $out;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $lang = trim($node->getAttribute('hreflang'));
            $href = trim($node->getAttribute('href'));
            if ($lang === '' || $href === '') {
                continue;
            }
            $out[] = ['hreflang' => $lang, 'href' => $href];
        }

        return $out;
    }

    /**
     * @return array{block_count: int, parse_ok_count: int, malformed_count: int, types: list<string>}
     */
    private function extractJsonLd(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        $blockCount = $nodes === false ? 0 : $nodes->length;
        $ok = 0;
        $bad = 0;
        $types = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $raw = trim((string) $node->textContent);
                if ($raw === '') {
                    $bad++;

                    continue;
                }
                try {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $ok++;
                    foreach ($this->extractTypes($decoded) as $type) {
                        $types[] = $type;
                    }
                } catch (Throwable) {
                    $bad++;
                }
            }
        }

        return [
            'block_count' => $blockCount,
            'parse_ok_count' => $ok,
            'malformed_count' => $bad,
            'types' => array_values(array_unique($types)),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractTypes(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $types = [];
        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                $types = [...$types, ...$this->extractTypes($item)];
            }

            return $types;
        }

        if (isset($decoded['@type'])) {
            $type = $decoded['@type'];
            if (is_string($type) && $type !== '') {
                $types[] = $type;
            } elseif (is_array($type)) {
                foreach ($type as $t) {
                    if (is_string($t) && $t !== '') {
                        $types[] = $t;
                    }
                }
            }
        }

        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            foreach ($decoded['@graph'] as $item) {
                $types = [...$types, ...$this->extractTypes($item)];
            }
        }

        return $types;
    }
}
