<?php

namespace App\Support;

/**
 * Deterministic telephone candidate extraction from HTML for Evidence normalization.
 */
class WebsiteTelephoneExtractor
{
    /**
     * @return list<string> Raw telephone strings discovered in document order (deduped, max 20).
     */
    public function extract(string $html): array
    {
        $candidates = [];

        if (preg_match_all('/href\s*=\s*([\'"])\s*tel:([^\'"]+)\1/i', $html, $telMatches)) {
            foreach ($telMatches[2] as $raw) {
                $this->pushCandidate($candidates, $raw);
            }
        }

        if (preg_match_all(
            '/itemprop\s*=\s*([\'"])telephone\1[^>]*>\s*([^<]{3,64})\s*</i',
            $html,
            $itempropMatches,
        )) {
            foreach ($itempropMatches[2] as $raw) {
                $this->pushCandidate($candidates, $raw);
            }
        }

        if (preg_match_all(
            '/"telephone"\s*:\s*"([^"]{3,64})"/i',
            $html,
            $jsonLdMatches,
        )) {
            foreach ($jsonLdMatches[1] as $raw) {
                $this->pushCandidate($candidates, $raw);
            }
        }

        return array_slice($candidates, 0, 20);
    }

    /**
     * Normalize a telephone string to comparable digits (optional leading + preserved as country marker via digits only).
     */
    public function normalize(string $raw): ?string
    {
        $trimmed = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $trimmed = preg_replace('/^tel:/i', '', $trimmed) ?? $trimmed;
        $trimmed = trim($trimmed);

        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);

        if (! is_string($digits) || strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function pushCandidate(array &$candidates, string $raw): void
    {
        $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/^tel:/i', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '' || $this->normalize($value) === null) {
            return;
        }

        if (! in_array($value, $candidates, true)) {
            $candidates[] = $value;
        }
    }
}
