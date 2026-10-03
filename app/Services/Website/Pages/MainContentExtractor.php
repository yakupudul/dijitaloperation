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

    private const int MAX_OUTLINE = 60000;

    private const int MAX_TABLE_ROWS = 30;

    /** Inline elements: their text joins the surrounding line. */
    private const array INLINE = ['span', 'strong', 'b', 'em', 'i', 'a', 'small', 'sup', 'sub', 'mark', 'abbr', 'code', 'u', 's', 'del', 'ins', 'q', 'cite', 'time', 'label', 'font', 'bdi', 'bdo', 'kbd', 'var', 'wbr'];

    /**
     * Full HTML document (non-WordPress sites).
     *
     * @return array{title: ?string, meta_description: ?string, canonical: ?string, language: ?string, is_indexable: bool,
     *     h1: ?string, headings: list<array{level: int, text: string}>, content_text: string, content_outline: string, word_count: int}
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
        $outline = $main instanceof DOMNode ? $this->outline($main) : '';
        [$text, $headings] = $main instanceof DOMNode ? $this->read($main) : ['', []];
        $h1 = $this->firstLevel($headings, 1) ?? $documentH1;

        return $head + [
            'h1' => $h1,
            'headings' => $headings,
            'content_text' => $text,
            'content_outline' => $outline,
            'word_count' => $this->words($text),
        ];
    }

    /**
     * A content fragment (WordPress post content): already main content, only cleaned and read.
     *
     * @return array{h1: ?string, headings: list<array{level: int, text: string}>, content_text: string, content_outline: string, word_count: int}
     */
    public function fromFragment(string $html): array
    {
        $document = $this->load('<html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>');
        if ($document === null) {
            $text = $this->normalize(strip_tags($html));

            return ['h1' => null, 'headings' => [], 'content_text' => $text, 'content_outline' => $text, 'word_count' => $this->words($text)];
        }
        $xpath = new DOMXPath($document);
        $this->removeChrome($xpath);
        $body = $document->getElementsByTagName('body')->item(0);
        $outline = $body instanceof DOMNode ? $this->outline($body) : '';
        [$text, $headings] = $body instanceof DOMNode ? $this->read($body) : ['', []];

        return ['h1' => $this->firstLevel($headings, 1), 'headings' => $headings, 'content_text' => $text, 'content_outline' => $outline, 'word_count' => $this->words($text)];
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

    /**
     * The main content as a light Markdown outline for AI: "#"-headings, paragraphs, "- " / "1. " lists, tables,
     * "> " quotes, "S: / C:" for FAQ (details / dl), "[görsel: alt]" and "[metin](link)". Read before read() pads blocks.
     */
    private function outline(DOMNode $root): string
    {
        $blocks = [];
        $this->outlineBlocks($root, $blocks);
        $outline = '';
        foreach ($blocks as $block) {
            if ($block === '') {
                continue;
            }
            if (mb_strlen($outline) + mb_strlen($block) + 2 > self::MAX_OUTLINE) {
                break;
            }
            $outline .= ($outline === '' ? '' : "\n\n").$block;
        }

        return $outline;
    }

    /** @param list<string> $blocks */
    private function outlineBlocks(DOMNode $node, array &$blocks): void
    {
        $line = '';
        foreach ($node->childNodes as $child) {
            if ($this->isInline($child)) {
                $line .= $this->inline($child);

                continue;
            }
            $this->flush($line, $blocks);
            $this->outlineBlock($child, $blocks);
        }
        $this->flush($line, $blocks);
    }

    /** @param list<string> $blocks */
    private function outlineBlock(DOMNode $node, array &$blocks): void
    {
        if (! $node instanceof DOMElement) {
            return;
        }
        $tag = mb_strtolower($node->nodeName);
        switch (true) {
            case preg_match('/^h([1-6])$/', $tag, $m) === 1:
                $text = $this->inlineText($node);
                if ($text !== '') {
                    $blocks[] = str_repeat('#', (int) $m[1]).' '.mb_substr($text, 0, 500);
                }

                return;
            case $tag === 'p':
                $text = $this->inlineText($node);
                if ($text !== '') {
                    $blocks[] = $text;
                }

                return;
            case $tag === 'ul' || $tag === 'ol':
                $list = $this->listLines($node, 0);
                if ($list !== []) {
                    $blocks[] = implode("\n", $list);
                }

                return;
            case $tag === 'table':
                $table = $this->table($node);
                if ($table !== '') {
                    $blocks[] = $table;
                }

                return;
            case $tag === 'blockquote':
                $inner = [];
                $this->outlineBlocks($node, $inner);
                if ($inner !== []) {
                    $blocks[] = '> '.str_replace("\n", "\n> ", implode("\n\n", array_filter($inner)));
                }

                return;
            case $tag === 'details':
                $question = '';
                $answer = [];
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && mb_strtolower($child->nodeName) === 'summary') {
                        $question = $this->inlineText($child);
                    } elseif ($this->isInline($child)) {
                        $answer[] = $this->normalize($this->inline($child));
                    } else {
                        $this->outlineBlock($child, $answer);
                    }
                }
                $answerText = trim(implode(' ', array_filter($answer)));
                if ($question !== '' || $answerText !== '') {
                    $blocks[] = trim(($question !== '' ? 'S: '.$question : '').($answerText !== '' ? "\nC: ".$answerText : ''));
                }

                return;
            case $tag === 'dl':
                $lines = [];
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    $text = $this->inlineText($child);
                    $name = mb_strtolower($child->nodeName);
                    if ($text !== '' && ($name === 'dt' || $name === 'dd')) {
                        $lines[] = ($name === 'dt' ? 'S: ' : 'C: ').$text;
                    }
                }
                if ($lines !== []) {
                    $blocks[] = implode("\n", $lines);
                }

                return;
            case $tag === 'img':
                $alt = $this->normalize($node->getAttribute('alt'));
                if ($alt !== '') {
                    $blocks[] = '[görsel: '.mb_substr($alt, 0, 200).']';
                }

                return;
            case in_array($tag, ['br', 'hr', 'figure', 'picture', 'video', 'audio', 'source', 'canvas', 'button', 'input', 'select', 'textarea'], true):
                if ($tag === 'figure' || $tag === 'picture') {
                    $this->outlineBlocks($node, $blocks);
                }

                return;
            default:
                $this->outlineBlocks($node, $blocks);
        }
    }

    /** @return list<string> */
    private function listLines(DOMElement $list, int $depth): array
    {
        $lines = [];
        $ordered = mb_strtolower($list->nodeName) === 'ol';
        $number = 0;
        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || mb_strtolower($item->nodeName) !== 'li') {
                continue;
            }
            $text = '';
            $nested = [];
            foreach ($item->childNodes as $child) {
                $name = $child instanceof DOMElement ? mb_strtolower($child->nodeName) : '';
                if ($name === 'ul' || $name === 'ol') {
                    $nested = [...$nested, ...$this->listLines($child, $depth + 1)];
                } else {
                    $text .= ' '.$this->inline($child);
                }
            }
            $text = $this->normalize($text);
            if ($text !== '') {
                $number++;
                $lines[] = str_repeat('  ', $depth).($ordered ? $number.'. ' : '- ').mb_substr($text, 0, 1000);
            }
            $lines = [...$lines, ...$nested];
        }

        return $lines;
    }

    private function table(DOMElement $table): string
    {
        $rows = [];
        $xpath = new DOMXPath($table->ownerDocument ?? new DOMDocument);
        foreach ($xpath->query('.//tr', $table) ?: [] as $row) {
            if (count($rows) >= self::MAX_TABLE_ROWS + 1) {
                break;
            }
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(mb_strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '/', mb_substr($this->inlineText($cell), 0, 200));
                }
            }
            if (array_filter($cells, fn (string $c): bool => $c !== '') !== []) {
                $rows[] = '| '.implode(' | ', $cells).' |';
            }
        }
        if ($rows === []) {
            return '';
        }
        $columns = substr_count($rows[0], ' | ') + 1;
        array_splice($rows, 1, 0, ['|'.str_repeat(' --- |', $columns)]);

        return implode("\n", $rows);
    }

    private function isInline(DOMNode $node): bool
    {
        return $node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE
            || ($node instanceof DOMElement && in_array(mb_strtolower($node->nodeName), self::INLINE, true) && ! $this->hasBlockChild($node));
    }

    private function hasBlockChild(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && ! in_array(mb_strtolower($child->nodeName), [...self::INLINE, 'br', 'img'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Inline Markdown of a node: text, "[metin](link)", "[görsel: alt]"; a <br> is a space. */
    private function inline(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string) $node->textContent;
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = mb_strtolower($node->nodeName);
        if ($tag === 'br') {
            return ' ';
        }
        if ($tag === 'img') {
            $alt = $this->normalize($node->getAttribute('alt'));

            return $alt !== '' ? ' [görsel: '.mb_substr($alt, 0, 200).'] ' : '';
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->inline($child);
        }
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            $text = $this->normalize($inner);
            if ($text !== '' && $href !== '' && ! str_starts_with($href, '#') && preg_match('/^(javascript|data):/i', $href) !== 1) {
                return '['.$text.']('.$href.')';
            }
        }

        return $inner;
    }

    private function inlineText(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->isInline($child) || ! $child instanceof DOMElement ? $this->inline($child) : ' '.$this->blockText($child).' ';
        }

        return $this->normalize($text);
    }

    /** Text of a block found inside an inline context (a <div> in a <td>, a <p> in an <li>). */
    private function blockText(DOMElement $node): string
    {
        $parts = [];
        foreach ($node->childNodes as $child) {
            $parts[] = $this->isInline($child) || ! $child instanceof DOMElement ? $this->inline($child) : $this->blockText($child);
        }

        return implode(' ', $parts);
    }

    /** @param list<string> $blocks */
    private function flush(string &$line, array &$blocks): void
    {
        $text = $this->normalize($line);
        if ($text !== '') {
            $blocks[] = $text;
        }
        $line = '';
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

    /** @return array{title: null, meta_description: null, canonical: null, language: null, is_indexable: bool, h1: null, headings: list<never>, content_text: string, content_outline: string, word_count: int} */
    private function empty(): array
    {
        return ['title' => null, 'meta_description' => null, 'canonical' => null, 'language' => null, 'is_indexable' => true,
            'h1' => null, 'headings' => [], 'content_text' => '', 'content_outline' => '', 'word_count' => 0];
    }
}
