<?php

namespace Tests\Unit;

use App\Support\Meta\MetaRegionCountry;
use PHPUnit\Framework\TestCase;

/** Meta region names get their country back so Analiz › Ülke ve bölge never shows a region without a country. */
final class MetaRegionCountryTest extends TestCase
{
    public function test_regions_map_to_their_country(): void
    {
        $this->assertSame('GB', MetaRegionCountry::of('England'));
        $this->assertSame('DE', MetaRegionCountry::of('Nordrhein-Westfalen'));
        $this->assertSame('BE', MetaRegionCountry::of('Flemish Region'));
        $this->assertSame('NL', MetaRegionCountry::of('Zuid-Holland'));
        $this->assertSame('US', MetaRegionCountry::of('Washington, District of Columbia'));
        $this->assertSame('TR', MetaRegionCountry::of('İzmir Province'));
        $this->assertSame('', MetaRegionCountry::of('Atlantis'));
    }

    public function test_country_names_are_turkish(): void
    {
        $this->assertSame('Bilinmeyen ülke', MetaRegionCountry::countryName(''));
        if (class_exists(\Locale::class)) {
            $this->assertSame('Almanya', MetaRegionCountry::countryName('DE'));
        }
    }
}
