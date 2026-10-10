<?php

namespace App\Services\Ads;

use App\Models\BrandServiceArea;
use Illuminate\Support\Str;

/**
 * The city a channel's numbers belong to in Kazananlar (a market is city × service). Province names are read from any
 * text (Google's "Izmir", a profile address "Bornova, İzmir", a campaign "İzmir implant form") and written the one
 * way the province list writes them; a brand races in each city it has a branch or a service area in.
 */
final class MarketCity
{
    /** @var array<string, string>|null folded province name => province name */
    private static ?array $provinces = null;

    /** The province named in a text ("Izmir Province", "İZMİR", "Bornova, İzmir"), else null. */
    public static function canonical(?string $text): ?string
    {
        $folded = self::fold((string) $text);
        if ($folded === '') {
            return null;
        }
        $provinces = self::provinces();
        if (isset($provinces[$folded])) {
            return $provinces[$folded];
        }
        foreach ($provinces as $key => $name) {
            if (self::hasWord($folded, $key)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The first of the given cities a text names as a whole word ("Ankara implant form" → Ankara), else null.
     *
     * @param  list<string>  $cities
     */
    public static function named(string $text, array $cities): ?string
    {
        $folded = self::fold($text);
        foreach ($cities as $city) {
            $key = self::fold($city);
            if ($key !== '' && self::hasWord($folded, $key)) {
                return $city;
            }
        }

        return null;
    }

    /**
     * The cities a brand races in: physical branches first, then service areas; province names where they are one.
     *
     * @return list<string>
     */
    public static function brandCities(int $brandId): array
    {
        $out = [];
        foreach (BrandServiceArea::query()->where('brand_id', $brandId)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('id')->pluck('city_name') as $raw) {
            $raw = trim((string) $raw);
            $city = self::canonical($raw) ?? $raw;
            if ($city !== '' && ! in_array(self::fold($city), array_map(self::fold(...), $out), true)) {
                $out[] = mb_substr($city, 0, 80);
            }
        }

        return $out;
    }

    /** Whether two city names are the same city. */
    public static function same(?string $a, ?string $b): bool
    {
        return self::fold((string) $a) === self::fold((string) $b);
    }

    public static function fold(string $value): string
    {
        $value = trim(Str::ascii(mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $value))));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $value)));
    }

    private static function hasWord(string $folded, string $word): bool
    {
        return preg_match('/(^| )'.preg_quote($word, '/').'( |$)/', $folded) === 1;
    }

    /** @return array<string, string> */
    private static function provinces(): array
    {
        if (self::$provinces !== null) {
            return self::$provinces;
        }
        $out = [];
        foreach ((array) json_decode((string) @file_get_contents(resource_path('data/locations/provinces.json')), true) as $province) {
            $name = (string) ($province['name'] ?? '');
            if ($name !== '') {
                $out[self::fold($name)] = $name;
            }
        }
        // Longer names first so "Afyonkarahisar" wins over a shorter name inside it.
        uksort($out, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return self::$provinces = $out;
    }
}
