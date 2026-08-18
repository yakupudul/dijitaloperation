<?php

namespace App\Support\Options;

/**
 * Lightweight city suggestions keyed by ISO country code.
 * Not exhaustive — unknown cities use an explicit Other escape, not silent free text.
 */
final class CityOptions
{
    public const string OTHER = '__other__';

    /**
     * @return array<string, list<string>>
     */
    public static function byCountry(): array
    {
        return [
            'TR' => ['Adana', 'Ankara', 'Antalya', 'Bursa', 'Gaziantep', 'Istanbul', 'Izmir', 'Kayseri', 'Konya', 'Mersin'],
            'DE' => ['Berlin', 'Cologne', 'Frankfurt', 'Hamburg', 'Munich', 'Stuttgart'],
            'GB' => ['Birmingham', 'Edinburgh', 'Glasgow', 'Leeds', 'London', 'Manchester'],
            'US' => ['Austin', 'Chicago', 'Houston', 'Los Angeles', 'Miami', 'New York', 'San Francisco', 'Seattle'],
            'NL' => ['Amsterdam', 'Rotterdam', 'The Hague', 'Utrecht'],
            'FR' => ['Lyon', 'Marseille', 'Nice', 'Paris'],
            'AE' => ['Abu Dhabi', 'Dubai', 'Sharjah'],
            'SA' => ['Jeddah', 'Riyadh'],
            'AZ' => ['Baku'],
            'IQ' => ['Baghdad', 'Erbil'],
        ];
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
        $options[self::OTHER] = __('operator.forms.city_other');

        return $options;
    }

    public static function isCatalogCity(?string $countryCode, string $city): bool
    {
        return $city !== '' && $city !== self::OTHER && in_array($city, self::forCountry($countryCode), true);
    }
}
