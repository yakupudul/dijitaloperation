<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The brand's own colour read from its logo, for printed pieces that carry the brand (the Google review card). No colour
 * is stored on the brand, so the logo is the source: an SVG's most used colourful fill, or a raster logo's most common
 * colourful pixel. Greys, black and white never count. Cached per logo address for 30 days; the fallback is a dark ink.
 */
final class LogoAccent
{
    public const FALLBACK = '#202124';

    public static function for(?string $logoUrl): string
    {
        $url = trim((string) $logoUrl);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return self::FALLBACK;
        }

        return Cache::remember('logo-accent:'.md5($url), now()->addDays(30), static function () use ($url): string {
            try {
                $response = Http::timeout(5)->withHeaders(['Accept' => 'image/*'])->get($url);
                $body = $response->successful() ? (string) $response->body() : '';
            } catch (Throwable) {
                $body = '';
            }

            return $body !== '' && strlen($body) <= 4_000_000 ? (self::fromImage($body) ?? self::FALLBACK) : self::FALLBACK;
        });
    }

    /** The accent of an image's bytes (SVG text or a PNG/JPEG/WebP/GIF file), or null when it has no colourful part. */
    public static function fromImage(string $bytes): ?string
    {
        $head = ltrim(substr($bytes, 0, 512));

        return str_starts_with($head, '<') || str_contains($head, '<svg') ? self::fromSvg($bytes) : self::fromRaster($bytes);
    }

    public static function fromSvg(string $svg): ?string
    {
        preg_match_all('/(?:fill|stroke|stop-color|color)\s*[=:]\s*["\']?\s*(#[0-9a-f]{6}|#[0-9a-f]{3})\b/i', $svg, $m);
        $counts = [];
        foreach ($m[1] as $hex) {
            $hex = self::normalize($hex);
            if ($hex !== null && self::colourful(...self::rgb($hex))) {
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return null;
        }
        // Same count: the stronger (more saturated) colour wins, so a pale secondary does not beat the main one.
        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], self::saturation(...self::rgb($b))] <=> [$counts[$a], self::saturation(...self::rgb($a))]);

        return array_key_first($counts);
    }

    public static function fromRaster(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $small = imagecreatetruecolor(48, 48);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 48, 48, imagesx($image), imagesy($image));
        $buckets = [];
        for ($x = 0; $x < 48; $x++) {
            for ($y = 0; $y < 48; $y++) {
                $c = imagecolorat($small, $x, $y);
                if ((($c >> 24) & 0x7F) > 60) {
                    continue; // mostly transparent
                }
                [$r, $g, $b] = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                if (! self::colourful($r, $g, $b)) {
                    continue;
                }
                $key = ($r >> 4).'-'.($g >> 4).'-'.($b >> 4);
                $buckets[$key] ??= [0, 0, 0, 0];
                $buckets[$key] = [$buckets[$key][0] + 1, $buckets[$key][1] + $r, $buckets[$key][2] + $g, $buckets[$key][3] + $b];
            }
        }
        if ($buckets === []) {
            return null;
        }
        usort($buckets, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        [$n, $r, $g, $b] = $buckets[0];

        return sprintf('#%02x%02x%02x', intdiv($r, $n), intdiv($g, $n), intdiv($b, $n));
    }

    private static function normalize(string $hex): ?string
    {
        $hex = strtolower(ltrim($hex, '#'));
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return strlen($hex) === 6 ? '#'.$hex : null;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function saturation(int $r, int $g, int $b): float
    {
        $max = max($r, $g, $b) / 255;
        $min = min($r, $g, $b) / 255;
        $l = ($max + $min) / 2;

        return $max === $min ? 0.0 : ($max - $min) / (1 - abs(2 * $l - 1));
    }

    /** Not grey, not near-white, not near-black. */
    private static function colourful(int $r, int $g, int $b): bool
    {
        $l = (max($r, $g, $b) + min($r, $g, $b)) / 510;

        return self::saturation($r, $g, $b) >= 0.25 && $l >= 0.15 && $l <= 0.85;
    }
}
