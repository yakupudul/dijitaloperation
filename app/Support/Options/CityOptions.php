<?php

namespace App\Support\Options;

/**
 * Central Turkey province catalog; other countries accept an explicit custom city.
 */
final class CityOptions
{
    public const string OTHER = '__other__';

    /**
     * @return array<string, list<string>>
     */
    public static function byCountry(): array
    {
        return ['TR' => array_values(LocationOptions::cities())];
    }

    /**
     * @return list<string>
     */
    public static function forCountry(?string $countryCode): array
    {
        if ($countryCode === null || $countryCode === '') {
            return [];
        }

        $code = strtoupper($countryCode);

        return self::byCountry()[$code] ?? [];
    }

    /**
     * Options map for select components (value === label), plus an explicit Other escape.
     *
     * @return array<string, string>
     */
    public static function optionsForCountry(?string $countryCode): array
    {
        if ($countryCode === null || $countryCode === '') {
            return [];
        }

        $cities = self::forCountry($countryCode);
        $options = $cities === [] ? [] : (array_combine($cities, $cities) ?: []);
        if (strtoupper($countryCode) !== 'TR') {
            $options[self::OTHER] = __('operator.forms.city_other');
        }

        return $options;
    }

    public static function isCatalogCity(?string $countryCode, string $city): bool
    {
        return $city !== '' && $city !== self::OTHER && in_array($city, self::forCountry($countryCode), true);
    }
}
