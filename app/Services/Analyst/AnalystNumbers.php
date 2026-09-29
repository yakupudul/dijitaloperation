<?php

namespace App\Services\Analyst;

/** Numbers in operator text, Turkish or plain formatting: "1.234" = 1234, "12,5" = 12.5, "%35" = 35, "3.4" = 3.4. */
final class AnalystNumbers
{
    /** @return list<float> */
    public static function extract(string $text): array
    {
        if (! preg_match_all('/(?<![\p{L}\d])-?\d+(?:[.,]\d+)*/u', $text, $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[0] as $raw) {
            $number = self::parse($raw);
            if ($number !== null) {
                $out[] = $number;
            }
        }

        return $out;
    }

    public static function parse(string $raw): ?float
    {
        $raw = trim($raw);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $raw)) {
            return (float) str_replace('.', '', $raw);
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $raw)) {
            return (float) str_replace(['.', ','], ['', '.'], $raw);
        }
        if (preg_match('/^-?\d{1,3}(,\d{3})+$/', $raw)) {
            return (float) str_replace(',', '', $raw);
        }
        $normalized = str_replace(',', '.', $raw);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * A quoted number matches a pack number when it is equal after rounding (the AI may round "3,46" to "3,5" or
     * "1.234" to "1.200") — within 0.5 absolute or 5 % relative, whichever is larger.
     *
     * @param  list<float>  $packNumbers
     */
    public static function inPack(float $number, array $packNumbers): bool
    {
        $abs = abs($number);
        foreach ($packNumbers as $candidate) {
            if (abs($abs - abs($candidate)) <= max(0.5, 0.05 * abs($candidate))) {
                return true;
            }
        }

        return false;
    }
}
