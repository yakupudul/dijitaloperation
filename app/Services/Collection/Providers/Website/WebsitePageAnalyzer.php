<?php

namespace App\Services\Collection\Providers\Website;

use MoxDop\Website\Discovery\PublicUrlNormalizer;

/**
 * Deterministic, provider-neutral page analysis for Website Intelligence.
 *
 * This class intentionally emits observations, graph edges and explicit issue codes only.
 * It does not calculate an opaque SEO score and does not create Findings/Recommendations.
 */
final class WebsitePageAnalyzer
{
    public function __construct(
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $fetch
     * @return array<string, mixed>|null
     */
    public function contentStats(int $digitalAssetId, array $fetch, string $observedAt): ?array
    {
        if (! $this->isHtmlResponse($fetch)) {
            return null;
        }

        $body = (string) $fetch['body'];
        $url = $this->pageUrl($fetch);
        $text = $this->visibleText($body);
        $words = $text === '' ? [] : preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;

        $headingCounts = [];
        for ($level = 1; $level <= 6; $level++) {
            $headingCounts['h'.$level.'_count'] = preg_match_all('/<h'.$level.'\b[^>]*>/i', $body) ?: 0;
        }

        preg_match('/<html\b[^>]*\blang\s*=\s*["\']?([^"\'\s>]+)/i', $body, $langMatch);
        $language = isset($langMatch[1]) ? strtolower(trim(html_entity_decode((string) $langMatch[1], ENT_QUOTES | ENT_HTML5))) : null;
        $paragraphCount = preg_match_all('/<p\b[^>]*>/i', $body) ?: 0;

        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => array_merge([
                'word_count' => $wordCount,
                'visible_text_length' => mb_strlen($text),
                'content_hash' => hash('sha256', $text),
                'language' => $language,
                'paragraph_count' => $paragraphCount,
                // This is an observation hint, not an SEO failure: page type/context matters.
                'thin_content_hint' => $wordCount > 0 && $wordCount < 300,
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ], $headingCounts),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function linkEdges(
        int $digitalAssetId,
        string $html,
        string $siteSeed,
        string $resolutionBase,
        string $observedAt,
    ): array {
        $sourceUrl = $this->urls->normalizeAbsolute($resolutionBase) ?? $resolutionBase;
        preg_match_all('/<a\b([^>]*)>(.*?)<\/a\s*>/is', $html, $anchors, PREG_SET_ORDER);

        /** @var array<string, array<string, mixed>> $edges */
        $edges = [];
        foreach ($anchors as $anchor) {
            $attributes = (string) ($anchor[1] ?? '');
            $innerHtml = (string) ($anchor[2] ?? '');
            $href = $this->attribute($attributes, 'href');
            if ($href === null || $this->shouldIgnoreHref($href)) {
                continue;
            }

            $resolved = $this->urls->resolve($resolutionBase, html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
            if ($resolved === null) {
                continue;
            }

            $normalizedTarget = $this->urls->normalizeAbsolute($resolved) ?? $resolved;
            $isInternal = $this->urls->sameSite($siteSeed, $resolved);
            $rel = strtolower(trim((string) ($this->attribute($attributes, 'rel') ?? '')));
            $anchorText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($innerHtml), ENT_QUOTES | ENT_HTML5)) ?? '');
            $edgeIdentity = implode('|', [$sourceUrl, $normalizedTarget, $anchorText, $rel]);
            $edgeKey = hash('sha256', $edgeIdentity);

            if (isset($edges[$edgeKey])) {
                $edges[$edgeKey]['metadata']['occurrences'] = (int) ($edges[$edgeKey]['metadata']['occurrences'] ?? 1) + 1;
                continue;
            }

            $edges[$edgeKey] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => null,
                'edge_key' => $edgeKey,
                'source_url' => $sourceUrl,
                'target_url' => $resolved,
                'normalized_target_url' => $normalizedTarget,
                'is_internal' => $isInternal,
                'anchor_text' => $anchorText !== '' ? mb_substr($anchorText, 0, 1000) : null,
                'rel' => $rel !== '' ? mb_substr($rel, 0, 255) : null,
                'nofollow' => in_array('nofollow', preg_split('/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY) ?: [], true),
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => [
                    'occurrences' => 1,
                    'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
                ],
            ];
        }

        return array_values($edges);
    }

    /**
     * A URL is allowed into the collected page inventory only after it produced a real,
     * successful HTML page response. HTTP errors and soft/error templates remain issues,
     * not pages.
     *
     * @param array<string, mixed> $fetch
     */
    public function isInventoryEligible(array $fetch): bool
    {
        $status = is_numeric($fetch['status_code'] ?? null) ? (int) $fetch['status_code'] : null;

        return ($fetch['ok'] ?? false) === true
            && $status !== null
            && $status >= 200
            && $status < 400
            && $this->isHtmlResponse($fetch)
            && ! $this->looksLikeErrorTemplate($fetch);
    }

    /**
     * @param  array<string, mixed>  $fetch
     * @return list<array<string, mixed>>
     */
    public function issueSnapshots(int $digitalAssetId, array $fetch, string $observedAt): array
    {
        $url = $this->pageUrl($fetch);
        $status = is_numeric($fetch['status_code'] ?? null) ? (int) $fetch['status_code'] : null;
        $issues = [];

        if ($status !== null && $status >= 500) {
            $issues[] = $this->issue($digitalAssetId, $url, 'HTTP_5XX', 'critical', 'Sunucu 5xx yanıtı döndürüyor.', $observedAt, ['status_code' => $status]);
        } elseif ($status !== null && $status >= 400) {
            $issues[] = $this->issue($digitalAssetId, $url, 'HTTP_4XX', 'high', 'Sayfa 4xx yanıtı döndürüyor.', $observedAt, ['status_code' => $status]);
        } elseif (($fetch['ok'] ?? false) !== true && $status === null) {
            $issues[] = $this->issue($digitalAssetId, $url, 'FETCH_FAILED', 'high', 'Sayfa tarayıcı tarafından alınamadı.', $observedAt, ['error' => $fetch['error'] ?? null]);
        }

        $redirectCount = is_numeric($fetch['redirect_count'] ?? null) ? (int) $fetch['redirect_count'] : 0;
        if ($redirectCount >= 2) {
            $issues[] = $this->issue($digitalAssetId, $url, 'REDIRECT_CHAIN', 'medium', 'URL birden fazla yönlendirme adımından geçiyor.', $observedAt, ['redirect_count' => $redirectCount]);
        }

        if (! $this->isHtmlResponse($fetch)) {
            return $issues;
        }

        if ($this->looksLikeErrorTemplate($fetch)) {
            $issues[] = $this->issue(
                $digitalAssetId,
                $url,
                'INVALID_PAGE_RESPONSE',
                'high',
                'URL geçerli sayfa içeriği yerine 404 veya hata şablonu döndürüyor.',
                $observedAt,
                ['status_code' => $status, 'soft_error_template' => true],
            );

            return $issues;
        }

        $body = (string) $fetch['body'];
        $title = $this->firstTagText($body, 'title');
        if ($title === null || trim($title) === '') {
            $issues[] = $this->issue($digitalAssetId, $url, 'MISSING_TITLE', 'high', 'Sayfada title etiketi bulunamadı.', $observedAt);
        }

        if (! preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?description["\']?[^>]*>/i', $body)
            && ! preg_match('/<meta\b[^>]*\bcontent\s*=\s*["\'][^"\']*["\'][^>]*\bname\s*=\s*["\']?description["\']?[^>]*>/i', $body)) {
            $issues[] = $this->issue($digitalAssetId, $url, 'MISSING_META_DESCRIPTION', 'medium', 'Sayfada meta açıklaması bulunamadı.', $observedAt);
        }

        $h1Count = preg_match_all('/<h1\b[^>]*>/i', $body) ?: 0;
        if ($h1Count === 0) {
            $issues[] = $this->issue($digitalAssetId, $url, 'MISSING_H1', 'medium', 'Sayfada H1 başlığı bulunamadı.', $observedAt);
        } elseif ($h1Count > 1) {
            $issues[] = $this->issue($digitalAssetId, $url, 'MULTIPLE_H1', 'low', 'Sayfada birden fazla H1 başlığı bulunuyor.', $observedAt, ['h1_count' => $h1Count]);
        }

        $canonicalCount = preg_match_all('/<link\b[^>]*\brel\s*=\s*["\'][^"\']*canonical[^"\']*["\'][^>]*>/i', $body) ?: 0;
        if ($canonicalCount === 0) {
            $issues[] = $this->issue($digitalAssetId, $url, 'CANONICAL_MISSING', 'low', 'Sayfada canonical etiketi bulunamadı.', $observedAt);
        } elseif ($canonicalCount > 1) {
            $issues[] = $this->issue($digitalAssetId, $url, 'CANONICAL_MULTIPLE', 'medium', 'Sayfada birden fazla canonical etiketi bulunuyor.', $observedAt, ['canonical_count' => $canonicalCount]);
        }

        if (preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?robots["\']?[^>]*\bcontent\s*=\s*["\'][^"\']*noindex[^"\']*["\'][^>]*>/i', $body)
            || preg_match('/<meta\b[^>]*\bcontent\s*=\s*["\'][^"\']*noindex[^"\']*["\'][^>]*\bname\s*=\s*["\']?robots["\']?[^>]*>/i', $body)) {
            $issues[] = $this->issue($digitalAssetId, $url, 'NOINDEX', 'info', 'Sayfa arama motoru indekslemesine kapalı.', $observedAt);
        }

        return $issues;
    }

    /** @param array<string, mixed> $fetch */
    public function isHtmlResponse(array $fetch): bool
    {
        if (($fetch['ok'] ?? false) !== true || ! is_string($fetch['body'] ?? null) || trim((string) $fetch['body']) === '') {
            return false;
        }

        $contentType = strtolower((string) ($fetch['content_type'] ?? ''));
        if ($contentType === '') {
            return preg_match('/<(?:!doctype\s+html|html|head|body)\b/i', (string) $fetch['body']) === 1;
        }

        return str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml');
    }

    /** @param array<string, mixed> $fetch */
    private function looksLikeErrorTemplate(array $fetch): bool
    {
        if (! $this->isHtmlResponse($fetch)) {
            return false;
        }

        $body = (string) $fetch['body'];
        $title = mb_strtolower((string) ($this->firstTagText($body, 'title') ?? ''));
        $text = mb_strtolower(mb_substr($this->visibleText($body), 0, 12000));
        $haystack = $title.' '.$text;

        foreach ([
            '404 not found',
            'error 404',
            'page not found',
            'the page you are looking for could not be found',
            'sayfa bulunamadı',
            'sayfa bulunamadi',
            'aradığınız sayfa bulunamadı',
            'aradiginiz sayfa bulunamadi',
            'there has been a critical error on this website',
            'critical error on this website',
        ] as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $fetch */
    private function pageUrl(array $fetch): string
    {
        $candidate = (string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? '');

        return $this->urls->normalizeAbsolute($candidate) ?? $candidate;
    }

    private function visibleText(string $html): string
    {
        $withoutNoise = preg_replace([
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<svg\b[^>]*>.*?<\/svg>/is',
            '/<!--.*?-->/s',
        ], ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($withoutNoise), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function firstTagText(string $html, string $tag): ?string
    {
        if (! preg_match('/<'.preg_quote($tag, '/').'\b[^>]*>(.*?)<\/'.preg_quote($tag, '/').'>/is', $html, $match)) {
            return null;
        }

        return trim(html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5));
    }

    private function attribute(string $attributes, string $name): ?string
    {
        $quoted = '/\b'.preg_quote($name, '/').'\s*=\s*(["\'])(.*?)\1/is';
        if (preg_match($quoted, $attributes, $match)) {
            return trim((string) $match[2]);
        }
        $unquoted = '/\b'.preg_quote($name, '/').'\s*=\s*([^\s>]+)/i';
        if (preg_match($unquoted, $attributes, $match)) {
            return trim((string) $match[1]);
        }

        return null;
    }

    private function shouldIgnoreHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return true;
        }

        $lower = strtolower($href);

        return str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'data:');
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function issue(
        int $digitalAssetId,
        string $url,
        string $code,
        string $severity,
        string $message,
        string $observedAt,
        array $evidence = [],
    ): array {
        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'issue_code' => $code,
            'severity' => $severity,
            'message' => $message,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'evidence' => $evidence,
                'deterministic' => true,
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];
    }
}
