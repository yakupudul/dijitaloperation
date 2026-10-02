<?php

namespace App\Support\BrandIntelligence;

/**
 * Structural identity-label normalizer (version v1).
 *
 * Allowed:
 * - Unicode NFC (before and after case fold)
 * - trim leading/trailing whitespace
 * - collapse internal whitespace (incl. Unicode spaces) to single ASCII space
 * - UTF-8 case folding via mb_strtolower
 * - canonicalize U+0069 U+0307 (i + combining dot) → U+0069 so İ/i collide
 *
 * Explicitly NOT applied (identity-unsafe / semantic):
 * - Turkish locale mapping I→ı (would split English "LIFT" vs "Lift")
 * - translation, transliteration, stemming, lemmatization
 * - blind diacritic removal, blind punctuation stripping
 * - word sorting, stopword removal
 * - fuzzy / embedding / AI similarity
 *
 * Turkish I/İ/ı/i (v1 documented behavior):
 * - "I" → "i"
 * - "İ" → "i" (via i+combining-dot canonicalize)
 * - "i" → "i"
 * - "ı" → "ı" (distinct from "i")
 * - "Istanbul" ≡ "istanbul"
 * - "ıstanbul" ≢ "istanbul"
 */
final class IdentityLabelNormalizer
{
    public const string VERSION = 'v1';

    public function normalize(string $raw): string
    {
        $value = \Normalizer::normalize($raw, \Normalizer::FORM_C);
        if ($value === false) {
            $value = $raw;
        }

        $value = preg_replace('/^[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+|[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+$/u', '', $value) ?? $value;
        $value = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', $value) ?? $value;

        $value = mb_strtolower($value, 'UTF-8');

        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if ($value === false) {
            return '';
        }

        // PHP mb_strtolower("İ") → "i" + combining dot (U+0307). Treat as plain "i".
        return str_replace("i\u{0307}", 'i', $value);
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
