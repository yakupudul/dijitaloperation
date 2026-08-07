<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Deterministic HTML canonical link extraction for Website Diagnosis (RFC 6596 / HTML link rel=canonical).
 */
class CanonicalLinkParser
{
    public const MAX_HEAD_BYTES = 65536;

    /**
     * @return array{
     *     head_html: string|null,
     *     head_truncated: bool,
     *     head_complete: bool,
     *     canonical_hrefs: list<string>,
     *     absolute_canonical_hrefs: list<string>,
     *     relative_canonical_hrefs: list<string>
     * }
     */
    public function parse(?string $rawHtml): array
    {
        if ($rawHtml === null || trim($rawHtml) === '') {
            return [
                'head_html' => null,
                'head_truncated' => false,
                'head_complete' => false,
                'canonical_hrefs' => [],
                'absolute_canonical_hrefs' => [],
                'relative_canonical_hrefs' => [],
            ];
        }

        [$headHtml, $headTruncated, $headComplete] = $this->extractHead($rawHtml);

        if ($headHtml === null || trim($headHtml) === '') {
            return [
                'head_html' => $headHtml,
                'head_truncated' => $headTruncated,
                'head_complete' => $headComplete,
                'canonical_hrefs' => [],
                'absolute_canonical_hrefs' => [],
                'relative_canonical_hrefs' => [],
            ];
        }

        $hrefs = $this->extractCanonicalHrefs($headHtml);
        $absolute = [];
        $relative = [];

        foreach ($hrefs as $href) {
            if ($this->isAbsoluteHttpUrl($href)) {
                $absolute[] = $href;
            } else {
                $relative[] = $href;
            }
        }

        return [
            'head_html' => $headHtml,
            'head_truncated' => $headTruncated,
            'head_complete' => $headComplete && ! $headTruncated,
            'canonical_hrefs' => $hrefs,
            'absolute_canonical_hrefs' => array_values(array_unique($absolute)),
            'relative_canonical_hrefs' => array_values(array_unique($relative)),
        ];
    }

    public function isAbsoluteHttpUrl(string $href): bool
    {
        $parts = parse_url($href);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && is_string($parts['host'])
            && $parts['host'] !== '';
    }

    /**
     * @return array{0: string|null, 1: bool, 2: bool}
     */
    private function extractHead(string $rawHtml): array
    {
        if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $rawHtml, $matches) === 1) {
            $headInner = $matches[1];
            $truncated = false;

            if (strlen($headInner) > self::MAX_HEAD_BYTES) {
                $headInner = substr($headInner, 0, self::MAX_HEAD_BYTES);
                $truncated = true;
            }

            return [$headInner, $truncated, true];
        }

        $excerpt = $rawHtml;
        $truncated = false;

        if (strlen($excerpt) > self::MAX_HEAD_BYTES) {
            $excerpt = substr($excerpt, 0, self::MAX_HEAD_BYTES);
            $truncated = true;
        }

        return [$excerpt, $truncated, false];
    }

    /**
     * @return list<string>
     */
    private function extractCanonicalHrefs(string $headHtml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $wrapped = '<!DOCTYPE html><html><head>'.$headHtml.'</head><body></body></html>';
            $document = new DOMDocument;
            $loaded = $document->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            if ($loaded !== true) {
                return $this->extractCanonicalHrefsWithRegex($headHtml);
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//head//link');

            if ($nodes === false) {
                return $this->extractCanonicalHrefsWithRegex($headHtml);
            }

            $hrefs = [];

            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $rel = strtolower(trim($node->getAttribute('rel')));

                if ($rel === '') {
                    continue;
                }

                $tokens = preg_split('/\s+/', $rel) ?: [];

                if (! in_array('canonical', $tokens, true)) {
                    continue;
                }

                $href = trim($node->getAttribute('href'));

                if ($href !== '') {
                    $hrefs[] = $href;
                }
            }

            return $hrefs;
        } catch (Throwable) {
            return $this->extractCanonicalHrefsWithRegex($headHtml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<string>
     */
    private function extractCanonicalHrefsWithRegex(string $headHtml): array
    {
        if (preg_match_all('/<link\b[^>]*>/i', $headHtml, $matches) < 1) {
            return [];
        }

        $hrefs = [];

        foreach ($matches[0] as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            if (preg_match('/\brel\s*=\s*([\'"])(.*?)\1/i', $tag, $relMatch) !== 1) {
                continue;
            }

            $tokens = preg_split('/\s+/', strtolower(trim($relMatch[2]))) ?: [];

            if (! in_array('canonical', $tokens, true)) {
                continue;
            }

            if (preg_match('/\bhref\s*=\s*([\'"])(.*?)\1/i', $tag, $hrefMatch) !== 1) {
                continue;
            }

            $href = trim($hrefMatch[2]);

            if ($href !== '') {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }
}
