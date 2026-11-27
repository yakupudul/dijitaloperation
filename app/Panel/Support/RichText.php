<?php

namespace App\Panel\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A message written in the small editor (bold, italic, lists, links), kept as clean HTML: only a short list of tags
 * survives, every attribute but a safe link address is dropped, and scripts or styles never reach the page.
 */
final class RichText
{
    private const array TAGS = ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a'];

    private const array RENAME = ['b' => 'strong', 'i' => 'em', 'div' => 'p'];

    private const array DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'head', 'title', 'meta', 'link', 'form', 'textarea', 'select', 'button'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if ($html === strip_tags($html)) {
            return self::fromPlain(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="rich-root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('rich-root');
        if (! $root instanceof DOMElement) {
            return self::fromPlain(strip_tags($html));
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= self::node($child);
        }
        $out = (string) preg_replace('#(<p>(\s|<br>)*</p>)+$#', '', trim($out));

        return trim(strip_tags($out)) === '' ? '' : $out;
    }

    /** Plain text as paragraphs, for a message typed without the editor. */
    public static function fromPlain(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/\R{2,}/u', $text) ?: [$text];

        return implode('', array_map(fn (string $part): string => '<p>'.nl2br(e(trim($part)), false).'</p>', $parts));
    }

    /** The message without its formatting, for lists, searches and notices. */
    public static function plain(string $html): string
    {
        $text = (string) preg_replace(['#<br\s*/?>#i', '#</(p|li|ul|ol)>#i'], "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function node(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return e($node->textContent);
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        $tag = self::RENAME[$tag] ?? $tag;
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::node($child);
        }
        if (! in_array($tag, self::TAGS, true)) {
            return $inner;
        }
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (preg_match('#^(https?://|mailto:|tel:)#i', $href) !== 1) {
                return $inner;
            }

            return '<a href="'.e($href).'" target="_blank" rel="noopener nofollow">'.$inner.'</a>';
        }

        return '<'.$tag.'>'.$inner.'</'.$tag.'>';
    }
}
