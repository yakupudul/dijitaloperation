<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Deterministic sitemap XML normalization/parsing for Website Diagnosis (sitemaps.org protocol).
 */
class SitemapXmlParser
{
    public const MAX_BODY_BYTES = 65536;

    /**
     * @return array{
     *     body: string|null,
     *     body_truncated: bool,
     *     parse_ok: bool,
     *     root_element: string|null,
     *     url_count: int|null,
     *     malformed: bool
     * }
     */
    public function parse(?string $rawBody, ?int $statusCode): array
    {
        if ($statusCode !== 200 || $rawBody === null) {
            return [
                'body' => null,
                'body_truncated' => false,
                'parse_ok' => false,
                'root_element' => null,
                'url_count' => null,
                'malformed' => false,
            ];
        }

        $truncated = false;
        $body = $rawBody;

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
            $truncated = true;
        }

        $trimmed = trim($body);

        if ($trimmed === '') {
            return [
                'body' => $body,
                'body_truncated' => $truncated,
                'parse_ok' => false,
                'root_element' => null,
                'url_count' => null,
                'malformed' => true,
            ];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($trimmed, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            if ($loaded !== true || ! $document->documentElement instanceof DOMElement) {
                return [
                    'body' => $body,
                    'body_truncated' => $truncated,
                    'parse_ok' => false,
                    'root_element' => null,
                    'url_count' => null,
                    'malformed' => true,
                ];
            }

            $rootLocalName = strtolower($document->documentElement->localName);

            if (! in_array($rootLocalName, ['urlset', 'sitemapindex'], true)) {
                return [
                    'body' => $body,
                    'body_truncated' => $truncated,
                    'parse_ok' => false,
                    'root_element' => $rootLocalName !== '' ? $rootLocalName : null,
                    'url_count' => null,
                    'malformed' => true,
                ];
            }

            $childLocalName = $rootLocalName === 'urlset' ? 'url' : 'sitemap';
            $urlCount = 0;

            foreach ($document->documentElement->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->localName) === $childLocalName) {
                    $urlCount++;
                }
            }

            return [
                'body' => $body,
                'body_truncated' => $truncated,
                'parse_ok' => true,
                'root_element' => $rootLocalName,
                'url_count' => $urlCount,
                'malformed' => false,
            ];
        } catch (Throwable) {
            return [
                'body' => $body,
                'body_truncated' => $truncated,
                'parse_ok' => false,
                'root_element' => null,
                'url_count' => null,
                'malformed' => true,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
