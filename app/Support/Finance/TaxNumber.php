<?php

namespace App\Support\Finance;

/**
 * Vergi kimlik no (10 hane) and TC kimlik no (11 hane) carry a check digit: a mistyped number is caught before it
 * reaches an invoice. Both rules are the public ones the GİB and NVİ forms use.
 */
final class TaxNumber
{
    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    public static function valid(string $digits): bool
    {
        return match (strlen($digits)) {
            10 => self::vkn($digits),
            11 => self::tckn($digits),
            default => false,
        };
    }

    /** The error to show, or null when the number is empty or right. */
    public static function error(string $digits): ?string
    {
        if ($digits === '') {
            return null;
        }
        if (! in_array(strlen($digits), [10, 11], true)) {
            return 'Vergi no 10, TC kimlik no 11 hanedir.';
        }

        return self::valid($digits) ? null : (strlen($digits) === 10 ? 'Bu vergi no geçerli değil, bir hanesi yanlış yazılmış olabilir.' : 'Bu TC kimlik no geçerli değil, bir hanesi yanlış yazılmış olabilir.');
    }

    private static function vkn(string $d): bool
    {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $tmp = ((int) $d[$i] + (9 - $i)) % 10;
            $v = ($tmp * (2 ** (9 - $i))) % 9;
            if ($tmp !== 0 && $v === 0) {
                $v = 9;
            }
            $sum += $v;
        }

        return (10 - ($sum % 10)) % 10 === (int) $d[9];
    }

    private static function tckn(string $d): bool
    {
        if ($d[0] === '0') {
            return false;
        }
        $n = array_map('intval', str_split($d));
        $odd = $n[0] + $n[2] + $n[4] + $n[6] + $n[8];
        $even = $n[1] + $n[3] + $n[5] + $n[7];
        $tenth = (($odd * 7 - $even) % 10 + 10) % 10;

        return $tenth === $n[9] && array_sum(array_slice($n, 0, 10)) % 10 === $n[10];
    }
}
