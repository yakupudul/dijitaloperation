<?php

namespace MoxDop\Website\Discovery;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MoxDop\Website\Diagnosis\DocumentHeadParser;
use Throwable;

/**
 * Deterministic public HTML fact extraction for Discovery Evidence.
 */
final class PublicPageExtractor
{
    public function __construct(
        private readonly DocumentHeadParser $documentHead = new DocumentHeadParser,
        private readonly PublicUrlNormalizer $normalizer = new PublicUrlNormalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $finalUrl, string $html): array
    {
        $head = $this->documentHead->parse($html);
        $dom = $this->loadDom($html);
        $xpath = $dom instanceof DOMDocument ? new DOMXPath($dom) : null;

        $h1 = null;
        $navLabels = [];
        $sameSiteLinks = [];
        $phones = [];
        $emails = [];
        $social = [];
        $addresses = [];
        $htmlLang = null;

        if ($xpath instanceof DOMXPath) {
            $htmlLang = $this->firstAttr($xpath, '//html/@lang') ?? $this->firstAttr($xpath, '//html/@xml:lang');
            $h1 = $this->firstText($xpath, '//h1');
            $navLabels = $this->texts($xpath, '//nav//a', 40);
            $sameSiteLinks = $this->collectSameSiteLinks($xpath, $finalUrl, 80);
            $phones = $this->collectHrefValues($xpath, 'tel:', 20);
            $emails = $this->collectHrefValues($xpath, 'mailto:', 20);
            $social = $this->collectSocialLinks($xpath, $finalUrl);
            $addresses = $this->collectAddressLikeText($xpath);
        }

        return [
            'source_url' => $finalUrl,
            'title' => $head['title'] ?? null,
            'h1' => $h1,
            'meta_description' => $head['meta_description'] ?? null,
            'canonical_url' => $head['open_graph']['url'] ?? null,
            'html_lang' => $htmlLang,
            'hreflang' => $head['hreflang'] ?? [],
            'open_graph' => $head['open_graph'] ?? [],
            'json_ld' => $head['json_ld'] ?? [],
            'nav_labels' => $navLabels,
            'same_site_links' => $sameSiteLinks,
            'phones' => $phones,
            'emails' => $emails,
            'social_links' => $social,
            'address_candidates' => $addresses,
            'normalization_version' => DiscoveryConfig::VERSION,
        ];
    }

    private function loadDom(string $html): ?DOMDocument
    {
        try {
            $dom = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $dom;
        } catch (Throwable) {
            return null;
        }
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $nodes->item(0)?->textContent) ?? '');

        return $text === '' ? null : $text;
    }

    private function firstAttr(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->nodeValue);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function texts(DOMXPath $xpath, string $query, int $limit): array
    {
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (count($out) >= $limit) {
                break;
            }
            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return list<array{url: string, label: ?string}>
     */
    private function collectSameSiteLinks(DOMXPath $xpath, string $baseUrl, int $limit): array
    {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }

            $href = trim((string) $node->getAttribute('href'));
            $resolved = $this->normalizer->resolve($baseUrl, $href);
            if ($resolved === null || ! $this->normalizer->sameSite($baseUrl, $resolved)) {
                continue;
            }

            if (isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;

            $label = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            $out[] = [
                'url' => $resolved,
                'label' => $label === '' ? null : $label,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function collectHrefValues(DOMXPath $xpath, string $prefix, int $limit): array
    {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $href = trim((string) $node->getAttribute('href'));
            if (! str_starts_with(strtolower($href), $prefix)) {
                continue;
            }
            $value = trim(substr($href, strlen($prefix)));
            $value = explode('?', $value)[0] ?? $value;
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @return list<array{platform: string, url: string}>
     */
    private function collectSocialLinks(DOMXPath $xpath, string $baseUrl): array
    {
        $map = [
            'instagram.com' => 'instagram',
            'facebook.com' => 'facebook',
            'fb.com' => 'facebook',
            'linkedin.com' => 'linkedin',
            'youtube.com' => 'youtube',
            'youtu.be' => 'youtube',
            'twitter.com' => 'x',
            'x.com' => 'x',
            'tiktok.com' => 'tiktok',
        ];

        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $href = trim((string) $node->getAttribute('href'));
            $resolved = $this->normalizer->resolve($baseUrl, $href) ?? $this->normalizer->normalizeAbsolute($href);
            if ($resolved === null) {
                continue;
            }
            $host = $this->normalizer->registrableHost($resolved);
            if ($host === null) {
                continue;
            }
            foreach ($map as $needle => $platform) {
                if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                    if (isset($seen[$resolved])) {
                        continue 2;
                    }
                    $seen[$resolved] = true;
                    $out[] = [
                        'platform' => $platform,
                        'url' => $resolved,
                    ];

                    continue 2;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function collectAddressLikeText(DOMXPath $xpath): array
    {
        $out = [];
        foreach (['//address', '//*[contains(@class,"address") or contains(@class,"location") or contains(@id,"address") or contains(@id,"location")]'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
                if (mb_strlen($text) < 12 || mb_strlen($text) > 240) {
                    continue;
                }
                if (! in_array($text, $out, true)) {
                    $out[] = $text;
                }
                if (count($out) >= 8) {
                    return $out;
                }
            }
        }

        return $out;
    }
}
