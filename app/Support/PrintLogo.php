<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Site\SiteScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The brand's logo for printed pieces (the Google review card), taken from the header of its own website.
 *
 * brands.logo_url is the square site icon (BrandSiteLogo picks apple-touch / favicon for the round avatar), often
 * 16–180 px: on an A6 card it prints blurred (yakup 2026-11-19 "marka logoları kötü duruyor"). Here the home page is
 * read once, the logo images of its <header> (then the page's other "logo" images, an inline header SVG and the
 * schema "logo") are downloaded, white logos meant for a dark header and small icons are dropped, and the sharpest
 * wide one wins. Result (a data: address + the logo's own colour) is cached for 7 days per brand.
 */
final class PrintLogo
{
    private const MAX_BYTES = 1_500_000;

    private const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml'];

    /** @return array{uri: string, accent: string}|null */
    public static function for(?Brand $brand): ?array
    {
        if ($brand === null) {
            return null;
        }
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->first();
        $origin = $site !== null ? rtrim(SiteScope::origin($site), '/') : '';
        if (! str_starts_with($origin, 'http')) {
            return null;
        }

        $key = 'print-logo:v1:'.$brand->id.':'.md5($origin);
        $found = Cache::get($key);
        if (! is_array($found)) {
            $found = self::find($origin) ?? [];
            // A site that did not answer is asked again within the hour, a found logo is kept for a week.
            Cache::put($key, $found, $found !== [] ? now()->addDays(7) : now()->addHour());
        }

        return $found !== [] ? $found : null;
    }

    /** @return array{uri: string, accent: string}|null */
    public static function find(string $origin): ?array
    {
        $html = self::fetch($origin.'/')['body'] ?? '';
        if ($html === '') {
            return null;
        }
        $best = null;
        foreach (array_slice(self::candidates($html, $origin), 0, 6) as $i => $candidate) {
            // "logo-beyaz.svg", "Logo-white.png": made for a dark header or footer, invisible on a white card.
            if (preg_match('/(beyaz|white|negatif|negative|light)[^\/]*$/i', (string) parse_url($candidate['url'], PHP_URL_PATH))) {
                continue;
            }
            $image = $candidate['inline'] ?? null;
            $image ??= self::fetch($candidate['url'], true);
            if ($image === null) {
                continue;
            }
            $score = self::score($image['body'], $image['type']);
            if ($score === null) {
                continue;
            }
            $score -= $i * 1.5; // the site's own first logo wins unless a later one is clearly better (wide, sharp)
            if ($best === null || $score > $best['score']) {
                $best = ['score' => $score] + $image;
            }
        }
        if ($best === null) {
            return null;
        }

        return [
            'uri' => 'data:'.$best['type'].';base64,'.base64_encode($best['body']),
            'accent' => LogoAccent::fromImage($best['body']) ?? LogoAccent::FALLBACK,
        ];
    }

    /**
     * Logo candidates of a home page, best place first: <header> logo images, an inline <svg> logo in the header,
     * the page's other logo images, the schema.org "logo".
     *
     * @return list<array{url: string, inline?: array{body: string, type: string}}>
     */
    public static function candidates(string $html, string $origin): array
    {
        $html = mb_substr($html, 0, 600_000);
        preg_match('/<header\b.*?<\/header>/is', $html, $header);
        $headerHtml = $header[0] ?? '';
        $out = [];
        $images = static function (string $scope) use ($origin): array {
            $found = [];
            preg_match_all('/<img\b[^>]*>/i', $scope, $tags);
            foreach ($tags[0] as $tag) {
                $src = self::attribute($tag, 'src') ?: self::attribute($tag, 'data-src');
                $marker = strtolower(self::attribute($tag, 'class').' '.self::attribute($tag, 'id').' '.self::attribute($tag, 'alt').' '.$src);
                if ($src !== '' && ! str_starts_with($src, 'data:') && str_contains($marker, 'logo')) {
                    $found[] = ['url' => self::absolute($src, $origin)];
                }
            }

            return $found;
        };
        array_push($out, ...$images($headerHtml));
        if (preg_match_all('/<svg\b.*?<\/svg>/is', $headerHtml, $svgs)) {
            foreach ($svgs[0] as $svg) {
                if (str_contains(strtolower(mb_substr($svg, 0, 400)), 'logo') && ! preg_match('/<script|on\w+\s*=/i', $svg) && strlen($svg) < self::MAX_BYTES) {
                    $svg = str_contains($svg, 'xmlns=') ? $svg : preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svg, 1);
                    $out[] = ['url' => 'inline', 'inline' => ['body' => (string) $svg, 'type' => 'image/svg+xml']];
                    break;
                }
            }
        }
        array_push($out, ...$images(str_replace($headerHtml, '', mb_substr($html, 0, 120_000))));
        if (preg_match('/"logo"\s*:\s*(?:\{[^}]*?"url"\s*:\s*)?"([^"]+)"/', $html, $ld)) {
            $out[] = ['url' => self::absolute(stripslashes($ld[1]), $origin)];
        }
        // WordPress thumbnails ("logo-150x75.png"): the full-size original is tried first.
        $full = [];
        foreach ($out as $c) {
            $original = $c['url'] !== 'inline' ? (string) preg_replace('/-\d{2,4}x\d{2,4}(?=\.\w+(?:\.webp)?$)/', '', $c['url']) : 'inline';
            if ($original !== $c['url']) {
                $full[] = ['url' => $original];
            }
            $full[] = $c;
        }
        $out = $full;
        $seen = [];

        return array_values(array_filter($out, static function (array $c) use (&$seen): bool {
            if ($c['url'] !== 'inline' && isset($seen[$c['url']])) {
                return false;
            }
            $seen[$c['url']] = true;

            return true;
        }));
    }

    /**
     * How good a logo is for a white card, or null when it cannot be used: a white logo (made for a dark header),
     * a raster under 120 px wide, an unreadable file. Wide (wordmark) and sharp logos score higher; a vector counts
     * as a raster of 1200 px.
     */
    public static function score(string $body, string $type): ?float
    {
        if ($type === 'image/svg+xml') {
            if (preg_match('/<script|on\w+\s*=/i', $body)) {
                return null;
            }
            preg_match_all('/(?:fill|stroke|stop-color)\s*[=:]\s*["\']?\s*(#[0-9a-f]{3,6}\b|white|black|rgb\([^)]*\))/i', $body, $m);
            $colours = array_map('strtolower', $m[1]);
            $hasShapes = preg_match('/<(path|text|rect|circle|polygon|use|image)\b/i', $body) === 1;
            $dark = array_filter($colours, static fn (string $c): bool => ! self::nearWhite($c));
            // Shapes with no colour at all print black; a logo whose every colour is white is dropped.
            if (! $hasShapes || ($colours !== [] && $dark === [])) {
                return null;
            }
            preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $body, $box);
            $ratio = isset($box[2]) && (float) $box[2] > 0 ? (float) $box[1] / (float) $box[2] : 2.0;

            return ($ratio >= 1.8 ? 2.0 : 0.0) + 2.0;
        }
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($body);
        if ($image === false) {
            return null;
        }
        [$w, $h] = [imagesx($image), imagesy($image)];
        if ($w < 120 || $h < 20) {
            return null;
        }
        $small = imagecreatetruecolor(60, max(1, (int) round(60 * $h / $w)));
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
        $ink = 0;
        $total = imagesx($small) * imagesy($small);
        for ($x = 0; $x < imagesx($small); $x++) {
            for ($y = 0; $y < imagesy($small); $y++) {
                $c = imagecolorat($small, $x, $y);
                if ((($c >> 24) & 0x7F) > 90) {
                    continue;
                }
                if (min(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF) < 215) {
                    $ink++;
                }
            }
        }
        if ($ink < $total * 0.02) {
            return null; // white (or empty) logo
        }

        return ($w / $h >= 1.8 ? 2.0 : 0.0) + min(2.0, $w / 400);
    }

    private static function nearWhite(string $colour): bool
    {
        if (in_array($colour, ['white', '#fff', '#ffffff'], true)) {
            return true;
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $colour, $m)) {
            $hex = strlen($m[1]) === 3 ? $m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2] : $m[1];

            return min(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))) >= 235;
        }
        if (preg_match('/rgb\(\s*(\d+)\D+(\d+)\D+(\d+)/', $colour, $m)) {
            return min((int) $m[1], (int) $m[2], (int) $m[3]) >= 235;
        }

        return false;
    }

    /** @return array{body: string, type: string}|null */
    private static function fetch(string $url, bool $image = false): ?array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP logo check)'])->get($url);
        } catch (Throwable) {
            return null;
        }
        $body = (string) $response->body();
        if (! $response->successful() || $body === '' || strlen($body) > ($image ? self::MAX_BYTES : 3_000_000)) {
            return null;
        }
        if (! $image) {
            return ['body' => $body, 'type' => 'text/html'];
        }
        $head = ltrim(substr($body, 0, 512));
        $type = str_starts_with($head, '<') || str_contains($head, '<svg') ? 'image/svg+xml'
            : (string) (finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $body) ?: '');

        return in_array($type, self::TYPES, true) ? ['body' => $body, 'type' => $type] : null;
    }

    private static function attribute(string $tag, string $name): string
    {
        if (! preg_match('/(?<![\w-])'.$name.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $match)) {
            return '';
        }

        return html_entity_decode(trim(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')), ENT_QUOTES);
    }

    private static function absolute(string $url, string $origin): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return $origin.'/'.ltrim($url, '/');
    }
}
