<?php

namespace MoxDop\Website\Standards;

use App\Services\SeoTasks\SeoText;
use DOMDocument;
use DOMElement;
use DOMNode;
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

    /** Number of shingle hashes kept per page (bottom-k MinHash). */
    public const int SKETCH = 64;

    /** Stock photo hosts / file names; images from them are not counted as original. */
    private const string STOCK_IMAGE = '/(shutterstock|istockphoto|gettyimages|unsplash|pexels|freepik|depositphotos|adobestock|stock\.adobe|pixabay|dreamstime|123rf|stock-photo|stockphoto)/';

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
                    // Stars on the business's own LocalBusiness / Organization (self-serving review markup).
                    'self_rating' => filled($node['aggregateRating'] ?? null) || filled($node['review'] ?? null),
                ];
            }
        }

        $scripts = $xpath->query('//body//script')?->length ?? 0;
        $spaRoot = ($xpath->query('//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt" or @ng-version or @data-reactroot or @data-server-rendered]')?->length ?? 0) > 0;
        $snippetMeta = false;
        foreach ($xpath->query('//meta[@content]') ?: [] as $meta) {
            if ($meta instanceof DOMElement && in_array(mb_strtolower($meta->getAttribute('name')), ['robots', 'googlebot'], true)
                && preg_match('/(?:^|[\s,])(nosnippet|max-snippet\s*:\s*0)(?:$|[\s,])/i', $meta->getAttribute('content')) === 1) {
                $snippetMeta = true;
            }
        }
        $bingVerified = ($xpath->query('//meta[translate(@name, "MSVALIDATE", "msvalidate")="msvalidate.01"][string-length(@content) > 4]')?->length ?? 0) > 0;

        foreach ($xpath->query('//script|//style|//noscript|//svg|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $xpath->query('//body')?->item(0);
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($body?->textContent ?? '')) ?? '');
        $lower = mb_strtolower($text);

        $noSnippetWords = 0;
        foreach ($xpath->query('//body//*[@data-nosnippet]') ?: [] as $node) {
            $noSnippetWords += $this->wordCount((string) $node->textContent);
        }
        $main = $this->mainContent($xpath);
        $mainText = trim(preg_replace('/\s+/u', ' ', $main !== null ? (string) $main->textContent : '') ?? '');
        $headings = [];
        foreach ($xpath->query('//body//h2|//body//h3') ?: [] as $node) {
            $heading = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            if ($heading !== '' && count($headings) < 40) {
                $headings[] = mb_substr($heading, 0, 120);
            }
        }
        $images = ['total' => 0, 'with_alt' => 0, 'original' => 0];
        foreach ($main instanceof DOMElement ? $main->getElementsByTagName('img') : [] as $image) {
            if (! $image instanceof DOMElement || $images['total'] >= 200) {
                continue;
            }
            $src = mb_strtolower($image->getAttribute('src').' '.$image->getAttribute('data-src'));
            if (str_contains($src, 'data:image') || str_ends_with(trim($src), '.svg')) {
                continue;
            }
            $images['total']++;
            $alt = trim($image->getAttribute('alt')) !== '';
            $images['with_alt'] += $alt ? 1 : 0;
            $images['original'] += $alt && preg_match(self::STOCK_IMAGE, $src) !== 1 ? 1 : 0;
        }

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
        $telLinks = $tel;
        foreach ($organizations as $organization) {
            if ($organization['telephone'] !== null) {
                $tel[] = $organization['telephone'];
            }
        }

        $digitsText = preg_replace('/\D+/', '', $text) ?? '';
        $foldedText = ' '.SeoText::fold($text).' ';
        foreach ($organizations as &$organization) {
            $organization['phone_visible'] = $organization['telephone'] !== null ? str_contains($digitsText, $organization['telephone']) || in_array($organization['telephone'], $telLinks, true) : null;
            $organization['name_visible'] = filled($organization['name']) ? str_contains($foldedText, ' '.SeoText::fold((string) $organization['name']).' ') : null;
            $organization['postal_visible'] = filled($organization['postal_code']) ? str_contains($text, (string) $organization['postal_code']) : null;
        }
        unset($organization);

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
            'main_words' => $this->wordCount($mainText),
            'body_scripts' => $scripts,
            'spa_root' => $spaRoot,
            'nosnippet_meta' => $snippetMeta,
            'nosnippet_words' => $noSnippetWords,
            'bing_verified' => $bingVerified,
            'headings' => $headings,
            'images' => $images,
            'shingles' => $this->shingles($mainText),
            'editor' => preg_match('/(sorumlu (hekim|müdür)|mesul müdür|tıbbi editör|içerik editörü|editör\s*:|editor\s*:|medical(ly)? review(ed)?|tıbbi (olarak )?incele)/iu', $lower) === 1,
            'health_tourism_cert' => preg_match('/(sağlık turizmi yetki belgesi|uluslararası sağlık turizmi|health tourism (authori[sz]ation|certificate)|international health tourism)/iu', $lower) === 1,
            'spam_terms' => $this->spamTerms($lower),
            'text_folded' => mb_substr(mb_strtolower($text), 0, 20000),
        ];
    }

    /** The page's main content: <main>, [role=main] or <article>; else the body without header / nav / footer / aside. */
    private function mainContent(DOMXPath $xpath): ?DOMNode
    {
        foreach (['//main', '//*[@role="main"]', '//article'] as $query) {
            $node = $xpath->query($query)?->item(0);
            if ($node !== null && $this->wordCount((string) $node->textContent) >= 30) {
                return $node;
            }
        }
        $body = $xpath->query('//body')?->item(0);
        if ($body === null) {
            return null;
        }
        $clone = $body->cloneNode(true);
        $document = new DOMDocument;
        $document->appendChild($document->importNode($clone, true));
        foreach ((new DOMXPath($document))->query('//header|//nav|//footer|//aside|//*[@role="navigation" or @role="banner" or @role="contentinfo"]') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        return $document->documentElement;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Bottom-k MinHash sketch of the main text's 5-word shingles: two sketches estimate how much of the text two
     * pages share (UrlStandardEvaluator::similarity) without keeping the text in memory.
     *
     * @return list<int>
     */
    private function shingles(string $text): array
    {
        $words = explode(' ', SeoText::fold($text));
        if (count($words) < 30) {
            return [];
        }
        $hashes = [];
        for ($i = 0, $n = count($words) - 4; $i < $n; $i++) {
            $hashes[crc32(implode(' ', array_slice($words, $i, 5)))] = true;
        }
        $hashes = array_keys($hashes);
        sort($hashes);

        return array_slice($hashes, 0, self::SKETCH);
    }

    /** @return list<string> gambling / adult / pharma spam words (hacked or rented sections). */
    private function spamTerms(string $lower): array
    {
        preg_match_all('/\b(deneme bonusu|casino|kumarhane|canlı bahis|bahis siteleri|slot oyun\w*|bet\d{2,}|escort|viagra|cialis|porn\w*)\b/iu', $lower, $matches);

        return array_values(array_slice(array_unique(array_map('mb_strtolower', $matches[1] ?? [])), 0, 5));
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
