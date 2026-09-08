<?php

namespace App\Support\Options;

/**
 * ISO 3166-1 alpha-2 country catalog. Labels are operator-facing; values are stable codes.
 */
final class CountryOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return LocationOptions::countries();
    }

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $code = strtoupper($code);

        return self::options()[$code] ?? $code;
    }

    public static function formatHq(?string $city, ?string $countryCode): string
    {
        $city = $city !== null ? trim($city) : '';
        $country = self::label($countryCode);

        if ($city !== '' && $countryCode !== null && $countryCode !== '') {
            return $city.', '.$country;
        }

        if ($city !== '') {
            return $city;
        }

        if ($countryCode !== null && $countryCode !== '') {
            return $country;
        }

        return '—';
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && $code !== '' && array_key_exists(strtoupper($code), self::options());
    }
}
