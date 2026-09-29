<?php

namespace App\Services\Site;

/**
 * Side-by-side "mevcut / yeni" for the "AI ile yap" screen: the HTML is split into visible text blocks (paragraphs,
 * headings, list items); a block missing on the other side is highlighted. Pure; no I/O.
 */
final class SiteDiff
{
    /**
     * @return array{left: list<array{text: string, tag: string, changed: bool}>, right: list<array{text: string, tag: string, changed: bool}>}
     */
    public static function html(string $current, string $new): array
    {
        $left = self::blocks($current);
        $right = self::blocks($new);
        $leftKeys = array_flip(array_map(fn (array $b): string => self::key($b['text']), $left));
        $rightKeys = array_flip(array_map(fn (array $b): string => self::key($b['text']), $right));

        return [
            'left' => array_map(fn (array $b): array => $b + ['changed' => ! isset($rightKeys[self::key($b['text'])])], $left),
            'right' => array_map(fn (array $b): array => $b + ['changed' => ! isset($leftKeys[self::key($b['text'])])], $right),
        ];
    }

    /** @return list<array{text: string, tag: string}> */
    public static function blocks(string $html): array
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        preg_match_all('#<(h[1-6]|p|li|td|th|blockquote|dt|dd)\b[^>]*>(.*?)</\1>#is', $html, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $match) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text !== '') {
                $out[] = ['text' => $text, 'tag' => strtolower($match[1])];
            }
        }
        if ($out === [] && trim(strip_tags($html)) !== '') {
            foreach (preg_split('/\n{2,}/', trim(strip_tags($html))) ?: [] as $part) {
                if (trim($part) !== '') {
                    $out[] = ['text' => trim((string) preg_replace('/\s+/u', ' ', $part)), 'tag' => 'p'];
                }
            }
        }

        return $out;
    }

    private static function key(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
