<?php

namespace MoxDop\Website\Standards;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Faz 6: one bounded parse of a stored HTML snapshot into the signals the URL karnesi standards need
 * (E-E-A-T attribution, medical review, visible dates, FAQ content, structured data nodes, hreflang, NAP).
 * No HTTP, no JavaScript, no external entities. Absent signal = false; the caller decides applicability.
 */
final class PageSignalExtractor
{
    /** Organization-like schema.org types that carry NAP. */
    public const array ORGANIZATION_TYPES = [
        'Organization', 'LocalBusiness', 'Dentist', 'MedicalClinic', 'MedicalBusiness', 'MedicalOrganization', 'Physician',
        'Hospital', 'LegalService', 'Attorney', 'FinancialService', 'ProfessionalService', 'Store', 'Restaurant',
        'HealthAndBeautyBusiness', 'BeautySalon', 'HomeAndConstructionBusiness', 'AutoRepair', 'Hotel', 'EducationalOrganization',
    ];

    private const string DATE = '(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,2}\s+(ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık|january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{4})';

    /** @return array<string, mixed>|null */
    public function extract(string $url, string $html): ?array
    {
        $inspection = (new StoredSeoInspector)->inspect($url, $html);
        if ($inspection === null) {
            return null;
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // A space before every tag keeps words of adjacent blocks apart in textContent.
            $document->loadHTML('<?xml encoding="UTF-8">'.str_replace('<', ' <', $html), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);

        $nodes = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $decoded = json_decode(trim((string) $script->textContent), true);
            if (is_array($decoded)) {
                $this->collectNodes($decoded, $nodes);
            }
        }
        $types = [];
        $organizations = [];
        $dateModified = false;
        $jsonAuthor = false;
        $reviewedBy = false;
        foreach ($nodes as $node) {
            $nodeTypes = array_values(array_filter((array) ($node['@type'] ?? []), 'is_string'));
            array_push($types, ...$nodeTypes);
            $dateModified = $dateModified || filled($node['dateModified'] ?? null);
            $jsonAuthor = $jsonAuthor || filled($node['author'] ?? null);
            $reviewedBy = $reviewedBy || filled($node['reviewedBy'] ?? null) || filled($node['lastReviewed'] ?? null);
            if (array_intersect($nodeTypes, self::ORGANIZATION_TYPES) !== []) {
                $phone = is_string($node['telephone'] ?? null) ? $node['telephone'] : null;
                $address = $node['address'] ?? null;
                $organizations[] = [
                    'types' => $nodeTypes,
                    'name' => is_string($node['name'] ?? null) ? trim($node['name']) : null,
                    'telephone' => $phone !== null ? $this->digits($phone) : null,
                    'has_address' => filled($address),
                    'postal_code' => is_array($address) && is_scalar($address['postalCode'] ?? null) ? (string) $address['postalCode'] : null,
                    'locality' => is_array($address) && is_string($address['addressLocality'] ?? null) ? $address['addressLocality'] : null,
                ];
            }
        }

        foreach ($xpath->query('//script|//style|//noscript|//svg|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $xpath->query('//body')?->item(0);
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($body?->textContent ?? '')) ?? '');
        $lower = mb_strtolower($text);

        $authorMarkup = ($xpath->query('//a[@rel="author"]|//*[@itemprop="author"]|//meta[@name="author"][string-length(@content) > 1]|//*[contains(concat(" ", normalize-space(@class), " "), " author ") or contains(@class, "author-name") or contains(@class, "post-author") or contains(@class, "byline")]')?->length ?? 0) > 0;
        $authorText = preg_match('/\b(yazar|hazırlayan|yazan|written by|author)\s*:?\s*\p{Lu}/u', $text) === 1
            || preg_match('/\b(dr|dt|uzm|prof|doç|op|av|mali müşavir)\.\s*(dr\.|dt\.)?\s*\p{Lu}\p{Ll}+/u', $text) === 1;
        $reviewText = preg_match('/(tıbbi (olarak )?(incele|kontrol|onay)|medically reviewed|medical review|tarafından (incelendi|kontrol edildi|onaylandı)|hekim (onaylı|kontrolünde)|uzman (onaylı|kontrolünde)|editoryal (inceleme|kontrol))/iu', $lower) === 1;
        $timeElement = ($xpath->query('//time[@datetime or string-length(normalize-space()) > 5]')?->length ?? 0) > 0;
        $dateText = preg_match('/(son güncelleme|güncelleme tarihi|güncellenme tarihi|güncellendi|güncelleme|yayınlanma tarihi|yayın tarihi|yayınlandı|last updated|updated on|published on)\s*:?\s*'.self::DATE.'/iu', $lower) === 1;
        $updatedText = preg_match('/(son güncelleme|güncelleme tarihi|güncellenme tarihi|güncellendi|last updated|updated on)/iu', $lower) === 1;

        $questions = 0;
        foreach ($xpath->query('//h2|//h3|//h4|//summary|//dt|//button') ?: [] as $node) {
            if (str_ends_with(trim((string) $node->textContent), '?')) {
                $questions++;
            }
        }
        $faqHeading = preg_match('/(sıkça sorulan|sık sorulan|merak edilen|s\.s\.s|\bsss\b|\bfaq\b|frequently asked)/iu', $lower) === 1;
        $faqMarkup = ($xpath->query('//*[contains(@class, "faq") or contains(@class, "sss") or contains(@id, "faq") or contains(@id, "sss")]')?->length ?? 0) > 0;

        $tel = [];
        foreach ($xpath->query('//a[starts-with(translate(@href, "TEL", "tel"), "tel:")]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $digits = $this->digits($node->getAttribute('href'));
                if ($digits !== null) {
                    $tel[] = $digits;
                }
            }
        }
        foreach ($organizations as $organization) {
            if ($organization['telephone'] !== null) {
                $tel[] = $organization['telephone'];
            }
        }

        return [
            'lang' => $inspection['language'] !== '' ? $inspection['language'] : null,
            'hreflang' => $inspection['hreflang'],
            'hreflang_truncated' => $inspection['hreflang_truncated'],
            'internal_urls' => $inspection['internal_urls'],
            'h1_count' => $inspection['h1_count'],
            'jsonld_types' => array_values(array_unique($types)),
            'date_modified' => $dateModified,
            'organizations' => array_slice($organizations, 0, 5),
            'author' => $jsonAuthor || $authorMarkup || $authorText,
            'medical_review' => $reviewedBy || $reviewText,
            'visible_date' => $timeElement || $dateText,
            'visible_updated' => $updatedText || $dateModified && $timeElement,
            'faq_content' => $questions >= 3 || (($faqHeading || $faqMarkup) && $questions >= 2),
            'question_count' => $questions,
            'phones' => array_values(array_unique($tel)),
            'text_length' => mb_strlen($text),
            'text_folded' => mb_substr(mb_strtolower($text), 0, 20000),
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array<string, mixed>>  $nodes
     */
    private function collectNodes(array $node, array &$nodes, int $depth = 0): void
    {
        if ($depth > 8 || count($nodes) > 200) {
            return;
        }
        if (isset($node['@type'])) {
            $nodes[] = $node;
        }
        foreach ($node as $key => $value) {
            if (is_array($value) && $key !== 'address') {
                $this->collectNodes($value, $nodes, $depth + 1);
            }
        }
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }
}
