<?php

namespace App\Services\Site;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cevap öncelikli içerik (yakup, 2026-10-07): the article's question-and-answer section as FAQPage JSON-LD, built by
 * rules from the written HTML (no AI). Every <h2>/<h3> that ends with "?" is a question; its answer is the text of
 * the paragraphs and lists that follow it up to the next heading. Fewer than two questions → no schema. The schema
 * holds only what the article says, so search engines and AI assistants read the same answers the visitor sees.
 */
final class ArticleFaqSchema
{
    private const int MAX_QUESTIONS = 12;

    private const int MAX_ANSWER = 1000;

    /** @return array<string, mixed>|null */
    public static function fromHtml(string $html, ?string $language = null): ?array
    {
        $pairs = self::pairs($html);
        if (count($pairs) < 2) {
            return null;
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'inLanguage' => $language,
            'mainEntity' => array_map(fn (array $pair): array => [
                '@type' => 'Question',
                'name' => $pair['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair['answer']],
            ], $pairs),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return list<array{question: string, answer: string}> */
    public static function pairs(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }
        $pairs = [];
        $question = null;
        $answer = [];
        foreach (self::blocks($body) as $node) {
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                self::close($pairs, $question, $answer);
                $text = self::text($node);
                $question = in_array($tag, ['h2', 'h3'], true) && str_ends_with($text, '?') ? $text : null;
                $answer = [];

                continue;
            }
            if ($question !== null && in_array($tag, ['p', 'ul', 'ol'], true)) {
                $text = $tag === 'p' ? self::text($node) : implode('; ', array_filter(array_map(self::text(...), iterator_to_array($node->getElementsByTagName('li')))));
                if ($text !== '') {
                    $answer[] = $text;
                }
            }
        }
        self::close($pairs, $question, $answer);

        return array_slice($pairs, 0, self::MAX_QUESTIONS);
    }

    /**
     * Headings, paragraphs and lists in document order; wrappers (div, section) are walked into.
     *
     * @return list<DOMElement>
     */
    private static function blocks(DOMNode $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->nodeName), ['div', 'section', 'article', 'details', 'summary'], true)) {
                array_push($out, ...self::blocks($child));
            } else {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{question: string, answer: string}>  $pairs
     * @param  list<string>  $answer
     */
    private static function close(array &$pairs, ?string $question, array $answer): void
    {
        $text = trim(implode(' ', $answer));
        if ($question !== null && $text !== '') {
            $pairs[] = ['question' => mb_substr($question, 0, 300), 'answer' => mb_substr($text, 0, self::MAX_ANSWER)];
        }
    }

    private static function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
