<?php

namespace App\Services\SearchDemand;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MoxDop\Website\Diagnosis\DocumentHeadParser;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

final class CompetitorPageContentExtractor
{
    public const string VERSION = 'competitor-page-v1';

    public function __construct(
        private readonly DocumentHeadParser $heads = new DocumentHeadParser,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
    ) {}

    /**
     * @param  list<string>  $serviceExpressions
     * @param  list<string>  $locationExpressions
     * @return array<string, mixed>
     */
    public function extract(string $url, string $html, array $serviceExpressions, array $locationExpressions): array
    {
        $head = $this->heads->parse($html);
        $document = $this->loadDom($html);
        $xpath = $document instanceof DOMDocument ? new DOMXPath($document) : null;
        $normalizedText = $document instanceof DOMDocument
            ? $this->visibleText($document)
            : $this->normalizeText(strip_tags($html));
        $headings = $xpath instanceof DOMXPath ? $this->headings($xpath) : [];
        [$internalLinks, $externalLinks] = $xpath instanceof DOMXPath
            ? $this->links($xpath, $url)
            : [[], []];

        $payload = [
            'normalized_text' => mb_substr($normalizedText, 0, 250000),
            'title' => $this->boundedNullable($head['title'] ?? null, 2000),
            'meta_description' => $this->boundedNullable($head['meta_description'] ?? null, 4000),
            'h1' => $this->firstHeading($headings, 1),
            'headings' => $headings,
            'schema_summary' => is_array($head['json_ld'] ?? null) ? $head['json_ld'] : [],
            'internal_links' => $internalLinks,
            'external_links' => $externalLinks,
            'service_expressions' => $this->matches($normalizedText, $serviceExpressions),
            'location_expressions' => $this->matches($normalizedText, $locationExpressions),
        ];
        $fingerprintPayload = [
            'text' => $payload['normalized_text'],
            'title' => $payload['title'],
            'meta_description' => $payload['meta_description'],
            'headings' => $payload['headings'],
            'schema' => $payload['schema_summary'],
            'internal_link_urls' => array_column($internalLinks, 'url'),
            'external_link_urls' => array_column($externalLinks, 'url'),
        ];
        $payload['content_fingerprint'] = hash(
            'sha256',
            json_encode($fingerprintPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        );

        return $payload;
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

    private function visibleText(DOMDocument $document): string
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//script|//style|//noscript|//template|//svg|//form|//dialog');
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

        return $this->normalizeText((string) ($document->getElementsByTagName('body')->item(0)?->textContent ?? ''));
    }

    /** @return list<array{level:int,text:string}> */
    private function headings(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        if ($nodes === false) {
            return [];
        }
        $out = [];
        foreach ($nodes as $node) {
            if (count($out) >= 120) {
                break;
            }
            $text = $this->normalizeText((string) $node->textContent);
            if ($text === '') {
                continue;
            }
            $out[] = ['level' => (int) mb_substr(mb_strtolower($node->nodeName), 1), 'text' => mb_substr($text, 0, 1000)];
        }

        return $out;
    }

    /** @param list<array{level:int,text:string}> $headings */
    private function firstHeading(array $headings, int $level): ?string
    {
        foreach ($headings as $heading) {
            if ($heading['level'] === $level) {
                return $heading['text'];
            }
        }

        return null;
    }

    /** @return array{0:list<array{url:string,label:?string}>,1:list<array{url:string,label:?string}>} */
    private function links(DOMXPath $xpath, string $baseUrl): array
    {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            return [[], []];
        }
        $internal = [];
        $external = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $resolved = $this->urls->resolve($baseUrl, trim($node->getAttribute('href')));
            if ($resolved === null || isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;
            $row = [
                'url' => $resolved,
                'label' => $this->boundedNullable($this->normalizeText((string) $node->textContent), 500),
            ];
            if ($this->urls->sameSite($baseUrl, $resolved)) {
                if (count($internal) < 200) {
                    $internal[] = $row;
                }
            } elseif (count($external) < 200) {
                $external[] = $row;
            }
            if (count($internal) >= 200 && count($external) >= 200) {
                break;
            }
        }

        return [$internal, $external];
    }

    /** @param list<string> $expressions @return list<string> */
    private function matches(string $text, array $expressions): array
    {
        return collect($expressions)
            ->map(fn (string $value): string => $this->normalizeText($value))
            ->filter(function (string $value) use ($text): bool {
                if (mb_strlen($value) < 2) {
                    return false;
                }
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu';

                return preg_match($pattern, $text) === 1;
            })
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->take(100)
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function boundedNullable(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = mb_substr(trim((string) $value), 0, $length);

        return $value !== '' ? $value : null;
    }
}
