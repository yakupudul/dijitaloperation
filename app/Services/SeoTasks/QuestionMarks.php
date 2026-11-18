<?php

namespace App\Services\SeoTasks;

/**
 * Soru işareti (yakup 2026-11-18): a heading or title written as a question ends with "?". The writer prompt said
 * "no other heading ends with ?" (site-write-article-v11 … v15), so the writers dropped the mark from question
 * headings ("Kist tekrar oluşur mu", "Talep başı maliyet nasıl bulunur"). This puts it back by rule, without AI,
 * on every article MoxDOP writes or sends. Turkish and English only; other languages are left as written.
 *
 * Only a line that is plainly a question gets the mark: it ends with a question particle (mi / mı / mu / mü …) or a
 * question word (nedir, ne …), or holds a question word (nasıl, neden, hangi, kaç …) and ends with a finite verb
 * (olur, yapılır, gerekir, kullanmalı, var). Noun phrases ("Bonding mi ortodonti mi kararı", "Hangi kanaldan talep
 * geldiği") and lines that already hold a "?" anywhere ("X nedir? Kısa rehber") are left alone.
 */
final class QuestionMarks
{
    public const int TITLE_MAX = 60;

    private const string PARTICLE = '/^(mi|mı|mu|mü)(yim|yım|yum|yüm|sin|sın|sun|sün|yiz|yız|yuz|yüz|siniz|sınız|sunuz|sünüz|dir|dır|dur|dür|ler|lar|ydi|ydı|ydu|ydü)?$/u';

    /** Question words that end a question on their own ("Diastema nedir", "ilişkisi ne"). */
    private const array END_WORDS = ['nedir', 'nelerdir', 'ne', 'neler', 'nasıl', 'nasıldır', 'neden', 'nedendir', 'niçin', 'niye', 'kaç', 'kaçtır',
        'hangisi', 'hangisidir', 'hangileri', 'hangileridir', 'kim', 'kimdir', 'kimler', 'kimlerdir', 'nerede', 'neredir', 'nereye', 'nereden', 'ne zaman', 'ne kadar', 'ne demek', 'ne demektir'];

    /** Words that make a line ending in a verb a question ("Hangi durumlarda gerekir", "Ne kadar sürer"). */
    private const string ASK_WORD = '/(?<!\p{L})(ne|neden|niçin|niye|nasıl|hangi\p{L}*|kaç\p{L}*|kim|kime|kimi|kimde|kimler\p{L}*|nerede|nereye|nereden|neyi|neye|neyle|neler\p{L}*|ne zaman|ne kadar)(?!\p{L})/u';

    /** First words that make the line a question whatever follows ("Neden Özel Demiderm", "Nasıl çalışır"). */
    private const array FIRST_WORDS = ['neden', 'niçin', 'niye', 'nasıl'];

    /** Last words that end in -r / -ar but are not verbs. */
    private const array NOT_VERBS = ['kadar', 'zaman', 'doktor', 'lazer', 'faktör', 'demir', 'bakır', 'kontür', 'tümör', 'süper', 'skor', 'sektör',
        'filler', 'laser', 'hiçbir', 'birer', 'ikişer', 'ister', 'hurdalar', 'türler', 'kürler', 'eserler'];

    /** Words after which an -ler / -lar word is a verb. */
    private const array BEFORE_VERB = ['ne', 'nasıl', 'neden', 'niçin', 'niye', 'neyi', 'neye', 'kimi', 'kime', 'nerede', 'nereye', 'nereden', 'zaman', 'kadar'];

    private const array EN_FIRST = ['how', 'what', 'why', 'when', 'which', 'who', 'where', 'can', 'does', 'do', 'is', 'are', 'should', 'will', 'could', 'would'];

    /** True when the line is a question but has no "?" anywhere. */
    public static function missing(string $line, string $language = 'tr'): bool
    {
        $text = self::plain($line);
        if ($text === '' || str_contains($text, '?') || str_ends_with($text, ')') || str_ends_with($text, '!')) {
            return false;
        }

        return self::isQuestion(rtrim($text, " .:;,…\u{00A0}"), strtolower(substr($language, 0, 2)) ?: 'tr');
    }

    /** The line with "?" at its end when it is a question that lacks one; unchanged otherwise. */
    public static function fix(string $line, string $language = 'tr', ?int $max = null): string
    {
        if (! self::missing($line, $language)) {
            return $line;
        }
        $fixed = (string) preg_replace('/[\s.:;,…\x{00A0}]*$/u', '', $line).'?';
        // A title over its limit is not made longer: the quality gate names it and the article is corrected.
        if ($max !== null && mb_strlen(self::plain($fixed)) > max($max, mb_strlen(self::plain($line)))) {
            return $line;
        }

        return $fixed;
    }

    /** Every <h1>–<h6> of the HTML that is a question without "?" gets one (the mark goes after the last text). */
    public static function fixHtml(string $html, string $language = 'tr'): string
    {
        return (string) preg_replace_callback('/(<h([1-6])\b[^>]*>)(.*?)(<\/h\2>)/isu', function (array $m) use ($language): string {
            if (! self::missing(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $language)) {
                return $m[0];
            }
            // The mark goes after the last visible character, also when the heading ends inside an inline tag.
            $inner = (string) preg_replace('/[\s.:;,…]*((?:<\/[a-z0-9]+>\s*)*)$/iu', '?$1', rtrim($m[3]), 1);

            return $m[1].$inner.$m[4];
        }, $html);
    }

    /**
     * The article (title, meta_title, html) with the missing marks put back; translations inside are left to their
     * own call. Title and SEO title stay within 60 characters.
     *
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    public static function article(array $article, ?string $language = null): array
    {
        $language ??= (string) ($article['language'] ?? 'tr');
        if (is_string($article['title'] ?? null)) {
            $article['title'] = self::fix($article['title'], $language, self::TITLE_MAX);
        }
        if (is_string($article['meta_title'] ?? null) && $article['meta_title'] !== '') {
            $article['meta_title'] = self::fix($article['meta_title'], $language, self::TITLE_MAX);
        }
        if (is_string($article['html'] ?? null)) {
            $article['html'] = self::fixHtml($article['html'], $language);
        }

        return $article;
    }

    /**
     * Kalite kapısı: the question lines of the article that still have no "?" (after article(), only a title that would
     * pass 60 characters with it).
     *
     * @param  array<string, mixed>  $article
     * @return list<string>
     */
    public static function problems(array $article, ?string $language = null): array
    {
        $language ??= (string) ($article['language'] ?? 'tr');
        $lines = [(string) ($article['title'] ?? ''), (string) ($article['meta_title'] ?? '')];
        preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/isu', (string) ($article['html'] ?? ''), $m);
        foreach ($m[1] as $heading) {
            $lines[] = html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $out = [];
        foreach (array_unique(array_map(fn (string $l): string => self::plain($l), $lines)) as $line) {
            if (self::missing($line, $language)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    private static function isQuestion(string $text, string $language): bool
    {
        if ($language === 'en') {
            $words = preg_split('/\s+/u', mb_strtolower(trim(self::lastClause($text)))) ?: [];

            return count($words) >= 3 && in_array($words[0], self::EN_FIRST, true);
        }
        if ($language !== 'tr') {
            return false;
        }
        $clause = self::lower(self::lastClause($text));
        $words = array_values(array_filter(preg_split('/[\s,]+/u', trim($clause, " \t\"'“”«»")) ?: [], fn (string $w): bool => $w !== ''));
        if (count($words) < 2) {
            return false;
        }
        $last = trim($words[count($words) - 1], "\"'“”«»");
        $pair = $words[count($words) - 2].' '.$last;
        if (preg_match(self::PARTICLE, $last) === 1 || in_array($last, self::END_WORDS, true) || in_array($pair, self::END_WORDS, true)) {
            return true;
        }
        $whole = self::lower($text);
        $firstWords = preg_split('/\s+/u', trim($whole)) ?: [];
        if (in_array($firstWords[0] ?? '', self::FIRST_WORDS, true) && count($firstWords) >= 2) {
            return true;
        }

        $asks = preg_match(self::ASK_WORD, $clause) === 1;
        $particle = array_filter(array_slice($words, 0, -1), fn (string $w): bool => preg_match(self::PARTICLE, $w) === 1) !== [];
        if (! $asks && ! $particle) {
            return false;
        }
        if (self::verb($last)) {
            return true;
        }
        // "neyi söyler", "nasıl günceller", "ne zaman dolar", "hasta mı belirler": an -ler / -lar word right after a
        // question word or particle is a verb; after anything else ("hangi seçenekler") it is a plural noun.
        $before = $words[count($words) - 2];

        return preg_match('/(lar|ler)$/u', $last) === 1 && mb_strlen($last) >= 5
            && (in_array($before, self::BEFORE_VERB, true) || preg_match(self::PARTICLE, $before) === 1);
    }

    /** A finite Turkish verb form at the end of a line (aorist, -malı, -meli, -yor, -dır, var / yok). */
    private static function verb(string $word): bool
    {
        if (in_array($word, ['var', 'yok', 'gerek', 'lazım', 'mümkün', 'olası', 'yasak', 'şart', 'önemli', 'uygun', 'güvenli', 'normal', 'olağan',
            'gerekli', 'zararlı', 'etkili', 'mantıklı', 'doğru', 'yeterli', 'riskli', 'ağrılı', 'kalıcı', 'farklı', 'mümkündür'], true)) {
            return true;
        }
        if (mb_strlen($word) < 4 || in_array($word, self::NOT_VERBS, true)) {
            return false;
        }
        if (preg_match('/(malı|meli|malıdır|melidir|maz|mez|yor|ıyor|iyor|uyor|üyor|dır|dir|dur|dür|tır|tir|tur|tür)$/u', $word) === 1
            || preg_match('/(r|maz|mez|yor|malı|meli)(sın|sin|sun|sün|sınız|siniz|sunuz|sünüz|ız|iz|uz|üz|ım|im|um|üm)$/u', $word) === 1) {
            return true;
        }
        // Aorist (-r, -ar / -er, -ır / -ir / -ur / -ür); a plural noun (-lar / -ler) is not one, "ilerler" is.
        if (preg_match('/(lar|ler)$/u', $word) === 1 && preg_match('/r(lar|ler)$/u', $word) !== 1) {
            return false;
        }

        return preg_match('/[aeıioöuü]r$|(ar|er|ır|ir|ur|ür)$/u', $word) === 1;
    }

    /** The part after the last ":" or " - " / " – " ("Kısa yanıt: ölçü nasıl alınır" → "ölçü nasıl alınır"). */
    private static function lastClause(string $text): string
    {
        $parts = preg_split('/\s*(?::|\s[-–—]\s)\s*/u', $text) ?: [$text];

        return trim((string) end($parts)) !== '' ? (string) end($parts) : $text;
    }

    private static function lower(string $text): string
    {
        return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $text));
    }

    private static function plain(string $line): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $line));
    }
}
