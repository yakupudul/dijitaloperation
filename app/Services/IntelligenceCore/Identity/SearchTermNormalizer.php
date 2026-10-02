<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Support\IntelligenceCore\NormalizedSearchTerm;
use Illuminate\Support\Str;

/**
 * Canonical identity preserves diacritics. Folded text is clustering-only.
 */
final class SearchTermNormalizer
{
    public const string VERSION = 'search_term_v1';

    public function normalize(string $raw, ?string $languageCode = null, ?string $locale = null): NormalizedSearchTerm
    {
        $value = $this->normalizeUnicodeAndWhitespace($raw);

        if ($this->isTurkish($languageCode, $locale)) {
            $value = strtr($value, ['I' => 'ı', 'İ' => 'i']);
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = $this->normalizeUnicodeAndWhitespace($value);
        $value = str_replace("i\u{0307}", 'i', $value);

        $folded = Str::ascii(str_replace('ı', 'i', $value), 'tr');
        $folded = mb_strtolower($this->normalizeUnicodeAndWhitespace($folded), 'UTF-8');

        return new NormalizedSearchTerm(
            canonicalText: $value,
            foldedText: $folded,
            normalizationVersion: self::VERSION,
        );
    }

    private function normalizeUnicodeAndWhitespace(string $value): string
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (is_string($normalized)) {
            $value = $normalized;
        }

        $value = preg_replace(
            '/^[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+|[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+$/u',
            '',
            $value,
        ) ?? $value;

        return preg_replace(
            '/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u',
            ' ',
            $value,
        ) ?? $value;
    }

    private function isTurkish(?string $languageCode, ?string $locale): bool
    {
        $language = strtolower((string) $languageCode);
        $localeValue = strtolower((string) $locale);

        return $language === 'tr'
            || str_starts_with($language, 'tr-')
            || str_starts_with($localeValue, 'tr_')
            || str_starts_with($localeValue, 'tr-');
    }
}
