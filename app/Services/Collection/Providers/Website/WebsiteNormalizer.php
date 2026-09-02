<?php

namespace App\Services\Collection\Providers\Website;

use App\Support\CanonicalLinkParser;
use DOMDocument;
use DOMNode;
use DOMXPath;
use MoxDop\Website\Diagnosis\DocumentHeadParser;
use MoxDop\Website\Discovery\PublicPageExtractor;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

/**
 * Public HTTP/HTML observations → Data Pool snapshot records.
 * Does not create Findings or Evidence.
 */
final class WebsiteNormalizer
{
    public function __construct(
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
        private readonly DocumentHeadParser $heads = new DocumentHeadParser,
        private readonly CanonicalLinkParser $canonicals = new CanonicalLinkParser,
        private readonly PublicPageExtractor $extractor = new PublicPageExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    public function urlRecord(int $digitalAssetId, string $normalizedUrl, string $source, ?string $observedAt = null): array
    {
        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'asset_id' => (string) $digitalAssetId,
            'normalized_url' => $normalizedUrl,
            'source_timezone' => 'UTC',
            'metadata' => [
                'source' => $source,
                'observed_at' => $observedAt,
                'coverage' => 'partial',
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    public function httpSnapshot(int $digitalAssetId, array $fetch, string $observedAt): array
    {
        $url = (string) ($fetch['requested_url'] ?? $fetch['final_url'] ?? '');

        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'requested_url' => $fetch['requested_url'] ?? $url,
                'final_url' => $fetch['final_url'] ?? null,
                'status_code' => $fetch['status_code'] ?? null,
                'content_type' => $fetch['content_type'] ?? null,
                'bytes' => $fetch['bytes'] ?? null,
                'redirect_count' => $fetch['redirect_count'] ?? null,
                'ok' => (bool) ($fetch['ok'] ?? false),
                'error' => $fetch['error'] ?? null,
                'http_200_neq_healthy' => true,
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $fetch
     * @return array<string, mixed>|null
     */
    public function htmlSnapshot(
        int $digitalAssetId,
        array $fetch,
        string $observedAt,
        ?string $previousHtmlHash,
        ?array $previousSemanticMetadata = null,
    ): ?array {
        $body = $fetch['body'] ?? null;
        if (! is_string($body) || $body === '') {
            return null;
        }

        $finalUrl = (string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? '');
        $url = $this->normalizeUrl($finalUrl);
        if ($url === null) {
            return null;
        }

        $htmlHash = hash('sha256', $body);
        $changeState = match (true) {
            $previousHtmlHash === null => 'first_seen',
            hash_equals($previousHtmlHash, $htmlHash) => 'unchanged',
            default => 'changed',
        };
        $semantic = $this->semanticSnapshot($body);
        $previousComponents = is_array($previousSemanticMetadata['semantic_component_hashes'] ?? null)
            ? $previousSemanticMetadata['semantic_component_hashes']
            : null;
        $previousSemanticHash = is_string($previousSemanticMetadata['semantic_hash'] ?? null)
            ? $previousSemanticMetadata['semantic_hash']
            : null;
        $semanticState = match (true) {
            $previousSemanticHash === null => 'baseline_created',
            hash_equals($previousSemanticHash, $semantic['hash']) => 'no_meaningful_change',
            default => 'meaningful_change',
        };
        $changedFields = $previousComponents === null
            ? []
            : array_values(array_keys(array_filter(
                $semantic['components'],
                static fn (string $hash, string $key): bool => ! isset($previousComponents[$key])
                    || ! hash_equals((string) $previousComponents[$key], $hash),
                ARRAY_FILTER_USE_BOTH,
            )));

        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'requested_url' => $fetch['requested_url'] ?? null,
            'final_url' => $fetch['final_url'] ?? null,
            'status_code' => isset($fetch['status_code']) ? (int) $fetch['status_code'] : null,
            'content_type' => $fetch['content_type'] ?? null,
            'html_hash' => $htmlHash,
            'previous_html_hash' => $previousHtmlHash,
            'change_state' => $changeState,
            'html_bytes' => strlen($body),
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'artifact_format' => 'html',
                'artifact_compression' => 'gzip',
                'content_addressed' => true,
                'semantic_normalization_version' => 1,
                'semantic_hash' => $semantic['hash'],
                'semantic_change_state' => $semanticState,
                'semantic_changed_fields' => $changedFields,
                'semantic_component_hashes' => $semantic['components'],
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];
    }

    /**
     * Removes volatile page chrome and hashes visitor-visible content signals separately.
     * Raw HTML is still retained and compared independently by html_hash/change_state.
     *
     * @return array{hash:string,components:array<string,string>}
     */
    private function semanticSnapshot(string $html): array
    {
        $head = $this->heads->parse($html);
        $canonical = $this->canonicals->parse($html);
        $document = $this->loadDom($html);
        $xpath = $document instanceof DOMDocument ? new DOMXPath($document) : null;

        $components = [
            'title' => $this->semanticValue($head['title'] ?? null),
            'meta_description' => $this->semanticValue($head['meta_description'] ?? null),
            'canonical' => $this->semanticValue($canonical['canonical_hrefs'] ?? []),
            'h1' => $this->semanticValue($xpath instanceof DOMXPath ? $this->nodeTexts($xpath, '//h1') : null),
            'content' => $this->semanticValue($document instanceof DOMDocument
                ? $this->meaningfulBodyText($document)
                : strip_tags($html)),
        ];

        return [
            'hash' => hash('sha256', json_encode($components, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'components' => $components,
        ];
    }

    private function loadDom(string $html): ?DOMDocument
    {
        try {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $document;
        } catch (Throwable) {
            return null;
        }
    }

    private function meaningfulBodyText(DOMDocument $document): string
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//script|//style|//noscript|//template|//svg|//nav|//footer|//header|//aside|//form|//dialog');
        if ($nodes !== false) {
            $remove = [];
            foreach ($nodes as $node) {
                $remove[] = $node;
            }
            foreach ($remove as $node) {
                if ($node instanceof DOMNode) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        $primary = $xpath->query('//main[not(ancestor::main)]|//article[not(ancestor::main) and not(ancestor::article)]');
        $text = '';
        if ($primary !== false && $primary->length > 0) {
            foreach ($primary as $node) {
                $text .= ' '.$node->textContent;
            }
        } else {
            $text = (string) ($document->getElementsByTagName('body')->item(0)?->textContent ?? '');
        }

        return $text;
    }

    /** @return list<string> */
    private function nodeTexts(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }

        $values = [];
        foreach ($nodes as $node) {
            $value = $this->normalizeSemanticText((string) $node->textContent);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function semanticValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        return hash('sha256', $this->normalizeSemanticText(is_scalar($value) ? (string) $value : ''));
    }

    private function normalizeSemanticText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $fetch
     * @return list<array<string, mixed>>
     */
    public function htmlSnapshots(int $digitalAssetId, array $fetch, string $observedAt): array
    {
        $url = (string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? '');
        $body = is_string($fetch['body'] ?? null) ? $fetch['body'] : null;
        $head = $this->heads->parse($body);
        $canonical = $this->canonicals->parse($body);
        $extracted = $body !== null ? $this->extractor->extract($url, $body) : [];
        $h1 = is_string($extracted['h1'] ?? null) ? $extracted['h1'] : null;
        $jsonLd = is_array($head['json_ld'] ?? null) ? $head['json_ld'] : [];

        $metadata = [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'title' => $head['title'] ?? null,
                'meta_description' => $head['meta_description'] ?? null,
                'meta_robots' => $head['meta_robots'] ?? null,
                'canonical_hrefs' => $canonical['canonical_hrefs'] ?? [],
                'title_present' => (bool) ($head['title_present'] ?? false),
                'meta_description_present' => (bool) ($head['meta_description_present'] ?? false),
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];

        $heading = [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'h1' => $h1,
                'h1_present' => $h1 !== null && $h1 !== '',
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];

        $schema = [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'types' => $jsonLd['types'] ?? [],
                'block_count' => $jsonLd['block_count'] ?? 0,
                'parse_ok_count' => $jsonLd['parse_ok_count'] ?? 0,
                'malformed_count' => $jsonLd['malformed_count'] ?? 0,
                'schema_present_neq_rich_result' => true,
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];

        return [$metadata, $heading, $schema];
    }

    /**
     * @param  array<string, mixed>  $tls
     * @return array<string, mixed>
     */
    public function infraSnapshot(int $digitalAssetId, string $host, array $tls, string $observedAt): array
    {
        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'asset_id' => (string) $digitalAssetId,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'host' => $host,
                'tls' => $tls,
                'present' => (bool) ($tls['present'] ?? false),
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $lab
     * @return array<string, mixed>
     */
    public function performanceMeasurement(int $digitalAssetId, string $url, string $strategy, array $lab, string $observedAt): array
    {
        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'strategy' => $strategy,
            'source_timezone' => 'UTC',
            'metadata' => array_merge($lab, [
                'lab_neq_field' => true,
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ]),
        ];
    }

    public function normalizeUrl(string $url): ?string
    {
        return $this->urls->normalizeAbsolute($url);
    }
}
