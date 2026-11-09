<?php

namespace App\Support\Sales;

/**
 * Phone numbers typed into forms ("+90 0538…", "0090 538…", "538 861 02 88", a missing digit) turned into the
 * international digits wa.me needs, or a plain reason why they cannot be used. Rules only: a number that is wrong
 * cannot be guessed, so a wrong one never opens WhatsApp to a stranger; the card says what is wrong instead.
 */
final class PhoneNumber
{
    /**
     * @return array{digits: ?string, issue: ?string}
     */
    public static function check(?string $raw): array
    {
        $raw = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return ['digits' => null, 'issue' => null];
        }
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        // Turkish shapes: the country code typed twice, or with the trunk 0 kept after it.
        if (preg_match('/^(?:90)+0?(\d{10})$/', $digits, $m) === 1 && (str_starts_with($digits, '90') && strlen($digits) >= 12)) {
            $digits = '90'.$m[1];
        } elseif (preg_match('/^0(\d{10})$/', $digits, $m) === 1 && ! $international) {
            $digits = '90'.$m[1];
        } elseif (strlen($digits) === 10 && ! $international && $digits[0] === '5') {
            $digits = '90'.$digits;
        }

        if (str_starts_with($digits, '90')) {
            $national = substr($digits, 2);
            if (strlen($national) !== 10) {
                return ['digits' => null, 'issue' => 'Numara '.strlen($national).' haneli; Türkiye numarası 10 haneli olur (5xx xxx xx xx).'];
            }
            if (! in_array($national[0], ['5', '2', '3', '4', '8'], true)) {
                return ['digits' => null, 'issue' => 'Numara '.$national[0].' ile başlıyor; Türkiye cep numarası 5 ile başlar.'];
            }

            return ['digits' => $digits, 'issue' => $national[0] !== '5' ? 'Sabit hat; WhatsApp\'ı olmayabilir.' : null];
        }
        if (! $international) {
            return ['digits' => null, 'issue' => 'Numara '.strlen($digits).' haneli ve ülke kodu yok; doğru yazılmamış görünüyor.'];
        }
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return ['digits' => null, 'issue' => 'Numara '.strlen($digits).' haneli; geçerli bir uluslararası numara değil.'];
        }

        return ['digits' => $digits, 'issue' => null];
    }

    /** "+90 538 861 02 88" for a Turkish number, "+<digits>" otherwise; null when the number cannot be used. */
    public static function pretty(?string $raw): ?string
    {
        $digits = self::check($raw)['digits'];
        if ($digits === null) {
            return null;
        }
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $n = substr($digits, 2);

            return '+90 '.substr($n, 0, 3).' '.substr($n, 3, 3).' '.substr($n, 6, 2).' '.substr($n, 8, 2);
        }

        return '+'.$digits;
    }
}
