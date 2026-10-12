<?php

namespace App\Support\Options;

use App\Services\SeoTasks\SeoText;
use Illuminate\Validation\ValidationException;

final class LocationOptions
{
    private static array $cache = [];

    public static function data(string $name): array
    {
        return self::$cache[$name] ??= json_decode(file_get_contents(resource_path('data/locations/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function countries(): array
    {
        return self::data('countries-tr');
    }

    public static function cities(string $country = 'TR'): array
    {
        return $country === 'TR' ? collect(self::data('provinces'))->pluck('name', 'name')->sort()->all() : [];
    }

    public static function districts(?string $city, string $country = 'TR'): array
    {
        if ($country !== 'TR' || blank($city)) {
            return [];
        }
        $province = collect(self::data('provinces'))->first(fn (array $p): bool => self::fold($p['name']) === self::fold($city));

        return $province ? collect(self::data('districts'))->where('provinceId', $province['id'])->pluck('name', 'name')->sort()->all() : [];
    }

    /** Same folding as the SEO engine (one implementation). */
    public static function fold(string $text): string
    {
        return SeoText::fold($text);
    }

    public static function normalizeArea(string $country, ?string $city, ?string $district, string $field = 'service_areas'): array
    {
        $country = strtoupper(trim($country));
        $city = trim((string) $city);
        $district = trim((string) $district);
        if (! isset(self::countries()[$country])) {
            throw ValidationException::withMessages([$field => 'Geçerli bir ülke seçin.']);
        }
        if ($district !== '' && $city === '') {
            throw ValidationException::withMessages([$field => 'İlçe için önce şehir seçin.']);
        }
        if ($country === 'TR') {
            if ($city !== '') {
                $city = collect(self::cities())->first(fn (string $name): bool => self::fold($name) === self::fold($city));
                if ($city === null) {
                    throw ValidationException::withMessages([$field => 'Türkiye için listeden geçerli bir şehir seçin.']);
                }
            }
            if ($district !== '') {
                $district = collect(self::districts($city))->first(fn (string $name): bool => self::fold($name) === self::fold($district));
                if ($district === null) {
                    throw ValidationException::withMessages([$field => 'Seçilen şehre bağlı bir ilçe seçin.']);
                }
            }
        }

        return ['country_code' => $country, 'city_name' => $city ?: null, 'district_name' => $district ?: null];
    }

    /**
     * Folded country / province / district names (folded name → name).
     *
     * @return array<string, string>
     */
    public static function expressions(): array
    {
        if (! isset(self::$cache['expressions'])) {
            $names = array_merge(array_values(self::countries()), array_values(self::data('countries-en')),
                array_column(self::data('provinces'), 'name'), array_column(self::data('districts'), 'name'),
                ['Türkiye', 'Turkey', 'Turkiye', 'İngiltere', 'ABD', 'Amerika']);
            self::$cache['expressions'] = [];
            foreach ($names as $name) {
                self::$cache['expressions'][self::fold($name)] = $name;
            }
        }

        return self::$cache['expressions'];
    }

    /** Remove longest whole expressions; preserve source text separately. */
    public static function strip(string $text): array
    {
        self::expressions();
        preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];
        $removed = [];
        $spans = [];
        for ($i = 0; $i < count($tokens); $i++) {
            for ($length = min(10, count($tokens) - $i); $length >= 1; $length--) {
                $key = implode(' ', array_map(fn (array $token): string => self::fold($token[0]), array_slice($tokens, $i, $length)));
                if (isset(self::$cache['expressions'][$key]) && ! self::slashGlued($text, $tokens, $i, $length)) {
                    $last = $tokens[$i + $length - 1];
                    $next = $tokens[$i + $length] ?? null;
                    if ($next && in_array(self::fold($next[0]), ['da', 'de', 'ta', 'te', 'dan', 'den', 'tan', 'ten', 'nin', 'nun', 'ya', 'ye', 'a', 'e', 'i', 'u', 's'], true)
                        && preg_match("/^['’]$/u", substr($text, $last[1] + strlen($last[0]), $next[1] - $last[1] - strlen($last[0])))) {
                        $last = $next;
                        $length++;
                    }
                    $spans[] = [$tokens[$i][1], $last[1] + strlen($last[0]) - $tokens[$i][1]];
                    $removed[] = self::$cache['expressions'][$key];
                    $i += $length - 1;
                    break;
                }
            }
        }
        foreach (array_reverse($spans) as [$offset, $length]) {
            $text = substr_replace($text, ' ', $offset, $length);
        }

        return ['text' => trim(preg_replace('/[\s,;|]+/u', ' ', $text) ?? ''), 'removed' => array_values(array_unique($removed))];
    }

    /**
     * "CAD/CAM": a match glued with "/" to a word that is not a place is part of that word ("Cad" is Chad), never a place;
     * "İstanbul/Kadıköy" (a place on both sides) still is.
     *
     * @param  list<array{0: string, 1: int}>  $tokens
     */
    private static function slashGlued(string $text, array $tokens, int $i, int $length): bool
    {
        $first = $tokens[$i];
        $last = $tokens[$i + $length - 1];
        $previous = $tokens[$i - 1] ?? null;
        $next = $tokens[$i + $length] ?? null;
        $end = $last[1] + strlen($last[0]);
        if ($previous !== null && $previous[1] + strlen($previous[0]) === $first[1] - 1 && ($text[$first[1] - 1] ?? '') === '/'
            && ! isset(self::$cache['expressions'][self::fold($previous[0])])) {
            return true;
        }

        return $next !== null && $next[1] === $end + 1 && ($text[$end] ?? '') === '/' && ! isset(self::$cache['expressions'][self::fold($next[0])]);
    }

    /**
     * strip() for SERVICE names (queries keep strip(): clusters stay location-free): a place goes only at the start
     * or the end, with its connector ("Ankara'da Saç Ekimi", "Saç Ekimi - Ankara", "Hair Transplant in Istanbul"),
     * never inside the phrase. A place at the start without a connector goes only when two words stay
     * ("Ankara Diş Hekimi" → "Diş Hekimi"; "Yumurtalık Kisti", "Araç Eskici" stay whole). A place glued to other
     * characters ("CAD/CAM") is never one.
     *
     * @return array{text: string, removed: list<string>}
     */
    public static function stripServiceName(string $name): array
    {
        self::expressions();
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $removed = [];
        $suffix = "(?:['’-]?(?:da|de|ta|te|dan|den|tan|ten|daki|deki|taki|teki))";
        // Start: "Ankara'da …", "Ankara …", "İstanbul Kadıköy'de …"
        for ($length = min(4, count($words) - 1); $length >= 1; $length--) {
            $head = array_slice($words, 0, $length);
            $last = (string) array_pop($head);
            if (preg_match('/^([\p{L}\p{M}]+)('.$suffix.')?[,:]?$/u', $last, $m) !== 1 || array_filter($head, fn (string $w): bool => preg_match('/^[\p{L}\p{M}]+$/u', $w) !== 1) !== []) {
                continue;
            }
            $key = self::fold(implode(' ', array_merge($head, [$m[1]])));
            $connector = ($m[2] ?? '') !== '' || str_ends_with($last, ',') || str_ends_with($last, ':');
            if (isset(self::$cache['expressions'][$key]) && ($connector || count($words) - $length >= 2)) {
                $removed[] = self::$cache['expressions'][$key];
                $words = array_slice($words, $length);
                break;
            }
        }
        // End: "… Ankara", "… - Ankara", "… in Istanbul", "… Ankara'da", "… İstanbul Kadıköy" (two places in a row)
        for ($round = 0; $round < 2; $round++) {
            $before = count($words);
            $words = self::stripEnd($words, $suffix, $removed);
            if (count($words) === $before) {
                break;
            }
        }

        return ['text' => trim(implode(' ', $words), " \t-–—|,:/"), 'removed' => array_values(array_unique($removed))];
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $removed
     * @return list<string>
     */
    private static function stripEnd(array $words, string $suffix, array &$removed): array
    {
        for ($length = min(4, count($words) - 1); $length >= 1; $length--) {
            $tail = array_slice($words, -$length);
            $first = (string) array_shift($tail);
            $tail = array_merge([$first], $tail);
            $lastWord = (string) end($tail);
            if (preg_match('/^([\p{L}\p{M}]+)'.$suffix.'?$/u', $lastWord, $m) !== 1
                || array_filter(array_slice($tail, 0, -1), fn (string $w): bool => preg_match('/^[\p{L}\p{M}]+$/u', $w) !== 1) !== []) {
                continue;
            }
            $key = self::fold(implode(' ', array_merge(array_slice($tail, 0, -1), [$m[1]])));
            if (! isset(self::$cache['expressions'][$key])) {
                continue;
            }
            $removed[] = self::$cache['expressions'][$key];
            $words = array_slice($words, 0, -$length);
            while ($words !== [] && in_array(self::fold((string) end($words)), ['in', 'at', 'near', ''], true)) {
                array_pop($words);
            }
            break;
        }

        return array_values($words);
    }

    /**
     * What a location name (as returned by strip()) refers to. District names can exist in several
     * provinces, so every reading is returned.
     *
     * @return list<array{kind: string, country_code: string, city: ?string, district: ?string}>
     */
    public static function describe(string $name): array
    {
        if (! isset(self::$cache['locations'])) {
            $index = [];
            $add = static function (string $label, array $reading) use (&$index): void {
                $index[self::fold($label)][] = $reading;
            };
            foreach ([self::countries(), self::data('countries-en')] as $countries) {
                foreach ($countries as $code => $label) {
                    $add((string) $label, ['kind' => 'country', 'country_code' => (string) $code, 'city' => null, 'district' => null]);
                }
            }
            foreach (['Türkiye', 'Turkey', 'Turkiye'] as $label) {
                $add($label, ['kind' => 'country', 'country_code' => 'TR', 'city' => null, 'district' => null]);
            }
            $provinces = [];
            foreach (self::data('provinces') as $province) {
                $provinces[$province['id']] = $province['name'];
                $add($province['name'], ['kind' => 'city', 'country_code' => 'TR', 'city' => $province['name'], 'district' => null]);
            }
            foreach (self::data('districts') as $district) {
                $add($district['name'], ['kind' => 'district', 'country_code' => 'TR', 'city' => $provinces[$district['provinceId']] ?? null, 'district' => $district['name']]);
            }
            self::$cache['locations'] = $index;
        }

        return array_values(array_unique(self::$cache['locations'][self::fold($name)] ?? [], SORT_REGULAR));
    }

    /**
     * Whether a location name falls inside the brand's service areas. Null when the brand has no
     * areas or the name is unknown: then nothing can be said.
     *
     * @param  iterable<array{country_code?: ?string, city_name?: ?string, district_name?: ?string}>  $areas
     */
    public static function withinAreas(string $name, iterable $areas): ?bool
    {
        $readings = self::describe($name);
        $areas = collect($areas)->map(fn ($area): array => (array) $area)->all();
        if ($readings === [] || $areas === []) {
            return null;
        }
        foreach ($readings as $reading) {
            foreach ($areas as $area) {
                $country = strtoupper((string) ($area['country_code'] ?? ''));
                $city = self::fold((string) ($area['city_name'] ?? ''));
                $district = self::fold((string) ($area['district_name'] ?? ''));
                if ($country !== $reading['country_code']) {
                    continue;
                }
                // Country-level mention, or the area covers the whole country.
                if ($reading['kind'] === 'country' || $city === '') {
                    return true;
                }
                if (self::fold((string) $reading['city']) !== $city) {
                    continue;
                }
                if ($reading['kind'] === 'city' || $district === '' || self::fold((string) $reading['district']) === $district) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Location-free text plus the locations it mentioned, split by the brand's service areas.
     *
     * @param  iterable<array<string, mixed>>  $areas
     * @return array{text: string, removed: list<string>, in_area: list<string>, out_of_area: list<string>}
     */
    public static function classify(string $text, iterable $areas): array
    {
        $stripped = self::strip($text);
        $in = [];
        $out = [];
        foreach ($stripped['removed'] as $name) {
            $within = self::withinAreas($name, $areas);
            if ($within === true) {
                $in[] = $name;
            } elseif ($within === false) {
                $out[] = $name;
            }
        }

        return ['text' => $stripped['text'], 'removed' => $stripped['removed'], 'in_area' => $in, 'out_of_area' => $out];
    }
}
