<?php

namespace App\Support\Meta;

/**
 * Country of a Meta insights "region" (state / province) name. Meta leaves the country empty on region rows of an ad
 * that delivered in more than one country, so the screens would show "England" or "Bayern" with no country. Turkish
 * provinces end in "Province"; the countries Moximu's brands advertise in abroad are listed by their Meta names.
 */
final class MetaRegionCountry
{
    /** @var array<string, list<string>> ISO country => Meta region names */
    private const array REGIONS = [
        'GB' => ['England', 'Scotland', 'Wales', 'Northern Ireland'],
        'DE' => ['Baden-Württemberg', 'Bayern', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hessen', 'Mecklenburg-Vorpommern', 'Niedersachsen', 'Nordrhein-Westfalen', 'Rheinland-Pfalz', 'Saarland', 'Sachsen', 'Sachsen-Anhalt', 'Schleswig-Holstein', 'Thüringen'],
        'NL' => ['Drenthe', 'Flevoland', 'Friesland', 'Gelderland', 'Groningen', 'Limburg', 'North Brabant', 'Noord-Brabant', 'Noord-Holland', 'North Holland', 'Overijssel', 'Utrecht', 'Zeeland', 'Zuid-Holland', 'South Holland'],
        'BE' => ['Flemish Region', 'Wallonia', 'Brussels'],
        'AT' => ['Burgenland', 'Carinthia', 'Lower Austria', 'Upper Austria', 'Salzburg', 'Styria', 'Tyrol', 'Vorarlberg', 'Vienna'],
        'CH' => ['Aargau', 'Basel-City', 'Basel-Landschaft', 'Bern', 'Fribourg', 'Geneva', 'Lucerne', 'Neuchâtel', 'St. Gallen', 'Solothurn', 'Thurgau', 'Ticino', 'Valais', 'Vaud', 'Zug', 'Zürich', 'Zurich'],
        'FR' => ['Île-de-France', 'Auvergne-Rhône-Alpes', 'Bourgogne-Franche-Comté', 'Brittany', 'Centre-Val de Loire', 'Corsica', 'Grand Est', 'Hauts-de-France', 'Normandy', 'Nouvelle-Aquitaine', 'Occitanie', 'Pays de la Loire', "Provence-Alpes-Côte d'Azur"],
        'SE' => ['Stockholm County', 'Västra Götaland County', 'Skåne County'],
        'DK' => ['Capital Region of Denmark', 'Region Zealand', 'Region of Southern Denmark', 'Central Denmark Region', 'North Denmark Region'],
        'IE' => ['County Dublin', 'Leinster', 'Munster', 'Connacht', 'Ulster'],
        'AE' => ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman'],
        'US' => ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming', 'Washington, District of Columbia', 'District of Columbia'],
    ];

    /** @var array<string, string>|null region name => ISO country */
    private static ?array $index = null;

    /** ISO country of a region name, or '' when unknown. */
    public static function of(string $region): string
    {
        if (str_ends_with($region, ' Province')) {
            return 'TR';
        }
        if (self::$index === null) {
            self::$index = [];
            foreach (self::REGIONS as $country => $names) {
                foreach ($names as $name) {
                    self::$index[$name] = $country;
                }
            }
        }

        return self::$index[$region] ?? '';
    }

    /** Turkish name of an ISO country ("DE" → "Almanya"), the code itself when unknown. */
    public static function countryName(string $country): string
    {
        if ($country === '' || $country === 'UNKNOWN') {
            return 'Bilinmeyen ülke';
        }
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-'.$country, 'tr') : '';

        return $name !== '' && $name !== $country ? $name : $country;
    }
}
