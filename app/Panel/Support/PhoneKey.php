<?php

namespace App\Panel\Support;

/** One key per phone number inside a brand: digits only, Turkish numbers reduced to their last ten digits. */
final class PhoneKey
{
    public static function of(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) < 7) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            return substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return substr($digits, 0, 32);
    }

    /** 0532 123 45 67 → 0532 *** ** 67 for users who may not see numbers. */
    public static function mask(?string $phone): string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 4 ? '****' : str_repeat('*', strlen($digits) - 2).substr($digits, -2);
    }
}
