<?php

namespace App\Services\Website\Pages;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * Readability-style main content of a page, for the `pages` table: header / footer / nav / aside and other chrome
 * removed, text normalised, H1–H3 kept in document order. Head fields (title, meta description, canonical, robots,
 * lang) are read from the full document. No HTML is returned or stored.
 */
final class MainContentExtractor
{
    /** Elements that are never main content. */
    private const string CHROME = '//script|//style|//noscript|//template|//svg|//form|//dialog|//iframe|//nav|//aside'
        .'|//header[not(ancestor::main) and not(ancestor::article)]|//footer[not(ancestor::main) and not(ancestor::article)]'
        .'|//*[@role="navigation" or @role="banner" or @role="contentinfo" or @role="complementary" or @role="search"]';

    /** class / id fragments of site chrome (menus, cookie bars, sidebars, breadcrumbs, pop-ups). */
    private const string CHROME_NAMES = '/(^|[\s_-])(menu|navbar|nav|navigation|site-header|site-footer|footer|sidebar|widget|breadcrumbs?|cookie|consent|popup|modal|offcanvas|share|social|related-posts|comments?)([\s_-]|$)/i';

    private const int MAX_TEXT = 250000;

    private const int MAX_HEADINGS = 120;

    /**
     * Full HTML document (non-WordPress sites).
     *
     * @return array{title: ?string, meta_description: ?string, canonical: ?string, language: ?string, is_indexable: bool,
     *     h1: ?string, headings: list<array{level: int, text: string}>, content_text: string, word_count: int}
     */
    public function fromDocument(string $html, string $url): array
    {
        $document = $this->load($html);
        if ($document === null) {
            return $this->empty();
        }
        $xpath = new DOMXPath($document);
        $head = $this->head($xpath, $url);
        $documentH1 = $this->firstH1($xpath);
        $this->removeChrome($xpath);
        $main = $this->mainNode($xpath) ?? $document->getElementsByTagName('body')->item(0);
        [$text, $headings] = $main instanceof DOMNode ? $this->read($main) : ['', []];
        $h1 = $this->firstLevel($headings, 1) ?? $documentH1;

        return $head + [
            'h1' => $h1,
            'headings' => $headings,
            'content_text' => $text,
            'word_count' => $this->words($text),
        ];
    }

    /**
     * A content fragment (WordPress post content): already main content, only cleaned and read.
     *
     * @return array{h1: ?string, headings: list<array{level: int, text: string}>, content_text: string, word_count: int}
     */
    public function fromFragment(string $html): array
    {
        $document = $this->load('<html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
        if ($document === null) {
            $text = $this->normalize(strip_tags($html));

            return ['h1' => null, 'headings' => [], 'content_text' => $text, 'word_count' => $this->words($text)];
        }
        $xpath = new DOMXPath($document);
        $this->removeChrome($xpath);
        $body = $document->getElementsByTagName('body')->item(0);
        [$text, $headings] = $body instanceof DOMNode ? $this->read($body) : ['', []];

        return ['h1' => $this->firstLevel($headings, 1), 'headings' => $headings, 'content_text' => $text, 'word_count' => $this->words($text)];
    }

    public function words(string $text): int
    {
        return $text === '' ? 0 : count(preg_split('/\s+/u', trim($text)) ?: []);
    }

    private function load(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
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

    /** @return array{title: ?string, meta_description: ?string, canonical: ?string, language: ?string, is_indexable: bool} */
    private function head(DOMXPath $xpath, string $url): array
    {
        $title = $this->normalize((string) ($xpath->query('//title')?->item(0)?->textContent ?? ''));
        $description = $this->meta($xpath, 'description');
        $robots = mb_strtolower((string) $this->meta($xpath, 'robots').' '.(string) $this->meta($xpath, 'googlebot'));
        $canonical = null;
        foreach ($xpath->query('//link[@rel]') ?: [] as $link) {
            if ($link instanceof DOMElement && in_array('canonical', preg_split('/\s+/', mb_strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $canonical = $this->absolute(trim($link->getAttribute('href')), $url);
                break;
            }
        }
        $lang = $xpath->query('//html[@lang]')?->item(0);
        $language = $lang instanceof DOMElement ? mb_strtolower(substr(trim($lang->getAttribute('lang')), 0, 2)) : null;

        return [
            'title' => $title !== '' ? mb_substr($title, 0, 500) : null,
            'meta_description' => $description !== null && $description !== '' ? mb_substr($description, 0, 1000) : null,
            'canonical' => $canonical,
            'language' => $language !== '' ? $language : null,
            'is_indexable' => ! str_contains($robots, 'noindex') && ! str_contains($robots, 'none'),
        ];
    }

    private function meta(DOMXPath $xpath, string $name): ?string
    {
        foreach ($xpath->query('//meta[@name]') ?: [] as $meta) {
            if ($meta instanceof DOMElement && mb_strtolower(trim($meta->getAttribute('name'))) === $name) {
                return $this->normalize($meta->getAttribute('content'));
            }
        }

        return null;
    }

    private function absolute(string $href, string $base): ?string
    {
        if ($href === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }

        return $origin.(str_starts_with($href, '/') ? $href : '/'.$href);
    }

    private function removeChrome(DOMXPath $xpath): void
    {
        $remove = [];
        foreach ($xpath->query(self::CHROME) ?: [] as $node) {
            $remove[] = $node;
        }
        foreach ($xpath->query('//body//*[@class or @id]') ?: [] as $node) {
            if ($node instanceof DOMElement && ! in_array(mb_strtolower($node->nodeName), ['main', 'article', 'body', 'h1', 'h2', 'h3'], true)
                && preg_match(self::CHROME_NAMES, $node->getAttribute('class').' '.$node->getAttribute('id')) === 1) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** <main>, [role=main], a single <article>, else the block holding most paragraph text. */
    private function mainNode(DOMXPath $xpath): ?DOMNode
    {
        foreach (['//main', '//*[@role="main"]'] as $query) {
            $node = $xpath->query($query)?->item(0);
            if ($node instanceof DOMNode && trim($node->textContent) !== '') {
                return $node;
            }
        }
        $articles = $xpath->query('//article');
        if ($articles !== false && $articles->length === 1) {
            return $articles->item(0);
        }
        $best = null;
        $bestScore = 0;
        foreach ($xpath->query('//body//div|//body//section') ?: [] as $candidate) {
            $score = 0;
            foreach ($xpath->query('./p|./*/p|./h1|./h2|./h3|./ul|./ol|./*/h2', $candidate) ?: [] as $child) {
                $score += mb_strlen(trim($child->textContent));
            }
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $bestScore >= 200 ? $best : null;
    }

    /** @return array{0: string, 1: list<array{level: int, text: string}>} */
    private function read(DOMNode $root): array
    {
        $headings = [];
        $xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument);
        foreach ($xpath->query('.//h1|.//h2|.//h3', $root) ?: [] as $node) {
            if (count($headings) >= self::MAX_HEADINGS) {
                break;
            }
            $text = $this->normalize((string) $node->textContent);
            if ($text !== '') {
                $headings[] = ['level' => (int) substr(mb_strtolower($node->nodeName), 1), 'text' => mb_substr($text, 0, 500)];
            }
        }
        // Block elements end with a space so words of adjacent blocks never glue together.
        foreach ($xpath->query('.//p|.//div|.//li|.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//br|.//td|.//th|.//section|.//article', $root) ?: [] as $block) {
            $block->appendChild($block->ownerDocument->createTextNode(' '));
        }

        return [mb_substr($this->normalize((string) $root->textContent), 0, self::MAX_TEXT), $headings];
    }

    /** @param list<array{level: int, text: string}> $headings */
    private function firstLevel(array $headings, int $level): ?string
    {
        foreach ($headings as $heading) {
            if ($heading['level'] === $level) {
                return $heading['text'];
            }
        }

        return null;
    }

    private function firstH1(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//h1')?->item(0);
        $text = $node instanceof DOMNode ? $this->normalize($node->textContent) : '';

        return $text !== '' ? mb_substr($text, 0, 500) : null;
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value);
    }

    /** @return array{title: null, meta_description: null, canonical: null, language: null, is_indexable: bool, h1: null, headings: list<never>, content_text: string, word_count: int} */
    private function empty(): array
    {
        return ['title' => null, 'meta_description' => null, 'canonical' => null, 'language' => null, 'is_indexable' => true,
            'h1' => null, 'headings' => [], 'content_text' => '', 'word_count' => 0];
    }
}
