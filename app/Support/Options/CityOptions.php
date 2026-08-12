<?php

namespace App\Support\Options;

/**
 * Lightweight city suggestions keyed by ISO country code.
 * Not exhaustive — combobox allows custom city entry when needed.
 */
final class CityOptions
{
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
     * Options map for select components (value === label).
     *
     * @return array<string, string>
     */
    public static function optionsForCountry(?string $countryCode): array
    {
        $cities = self::forCountry($countryCode);

        return array_combine($cities, $cities) ?: [];
    }
}
