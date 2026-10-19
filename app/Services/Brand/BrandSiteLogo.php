<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Site\SiteScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The brand's own logo, taken from its website (yakup 2026-10-19: "web sitesinden çekebildiğimiz markanın logosu olsun").
 *
 * Reads the home page once, picks the square site icon first (apple-touch-icon, the largest `<link rel=icon>`; it
 * fits the round-cornered avatar), then the WordPress / theme logo image, then /apple-touch-icon.png and /favicon.ico.
 * The image is copied to the public disk so the panel never hot-links a client site. A logo the operator typed in
 * the brand form is never replaced; only empty ones and earlier copies made here are refreshed.
 */
final class BrandSiteLogo
{
    public const DIRECTORY = 'brand-logos';

    private const MAX_BYTES = 1_000_000;

    /** SVG is skipped on purpose: served from the panel's own domain it could run script when opened directly. */
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];

    /** @return array{brands: int, saved: int, kept: int, missing: int} */
    public function refreshAll(): array
    {
        $totals = ['brands' => 0, 'saved' => 0, 'kept' => 0, 'missing' => 0];
        Brand::query()->operational()->orderBy('id')->each(function (Brand $brand) use (&$totals): void {
            $totals['brands']++;
            $result = $this->refresh($brand);
            $totals[$result]++;
        });

        return $totals;
    }

    /** @return 'saved'|'kept'|'missing' */
    public function refresh(Brand $brand): string
    {
        if (filled($brand->logo_url) && ! self::isOwnCopy((string) $brand->logo_url)) {
            return 'kept';
        }
        $site = DigitalAsset::query()->operational()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->first();
        if ($site === null) {
            return 'missing';
        }
        $origin = rtrim(SiteScope::origin($site), '/');
        if (! str_starts_with($origin, 'http')) {
            return 'missing';
        }

        foreach ($this->candidates($origin) as $url) {
            $image = $this->download($url);
            if ($image === null) {
                continue;
            }
            $path = self::DIRECTORY.'/'.$brand->id.'.'.$image['extension'];
            foreach (Storage::disk('public')->files(self::DIRECTORY) as $old) {
                if (str_starts_with(basename($old), $brand->id.'.')) {
                    Storage::disk('public')->delete($old);
                }
            }
            Storage::disk('public')->put($path, $image['body']);
            // The file name changes with the content so phones do not keep showing a cached older logo.
            $brand->forceFill(['logo_url' => Storage::disk('public')->url($path).'?v='.substr(md5($image['body']), 0, 8)])->save();

            return 'saved';
        }

        return 'missing';
    }

    public static function isOwnCopy(string $url): bool
    {
        return str_contains($url, '/storage/'.self::DIRECTORY.'/');
    }

    /** @return list<string> */
    public function candidates(string $origin): array
    {
        $html = '';
        try {
            $response = Http::timeout(12)->connectTimeout(5)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP logo check)'])->get($origin.'/');
            if ($response->successful()) {
                $html = mb_substr($response->body(), 0, 400_000);
            }
        } catch (Throwable) {
            // Unreachable site: only the well-known icon addresses below are tried.
        }

        return array_values(array_unique([...self::fromHtml($html, $origin), $origin.'/apple-touch-icon.png', $origin.'/favicon.ico']));
    }

    /**
     * Icon and logo addresses in the page, best first.
     *
     * @return list<string>
     */
    public static function fromHtml(string $html, string $origin): array
    {
        if ($html === '') {
            return [];
        }
        $touch = [];
        $icons = [];
        preg_match_all('/<link\b[^>]*>/i', $html, $links);
        foreach ($links[0] as $tag) {
            $rel = strtolower(self::attribute($tag, 'rel'));
            $href = self::attribute($tag, 'href');
            if ($href === '' || ! preg_match('/\bicon\b/', $rel)) {
                continue;
            }
            preg_match('/(\d+)x\d+/', self::attribute($tag, 'sizes'), $size);
            $entry = ['url' => self::absolute($href, $origin), 'size' => (int) ($size[1] ?? 0)];
            str_contains($rel, 'apple-touch-icon') ? $touch[] = $entry : $icons[] = $entry;
        }
        $bySize = fn (array $a, array $b): int => $b['size'] <=> $a['size'];
        usort($touch, $bySize);
        usort($icons, $bySize);

        $logos = [];
        preg_match_all('/<img\b[^>]*>/i', $html, $images);
        foreach ($images[0] as $tag) {
            $marker = strtolower(self::attribute($tag, 'class').' '.self::attribute($tag, 'id').' '.self::attribute($tag, 'alt'));
            $src = self::attribute($tag, 'src') ?: self::attribute($tag, 'data-src');
            if ($src !== '' && str_contains($marker, 'logo') && ! str_starts_with($src, 'data:')) {
                $logos[] = self::absolute($src, $origin);
            }
        }

        $big = array_filter($icons, fn (array $icon): bool => $icon['size'] >= 64);
        $small = array_filter($icons, fn (array $icon): bool => $icon['size'] < 64);

        return array_values(array_unique([
            ...array_column($touch, 'url'),
            ...array_column($big, 'url'),
            ...array_slice($logos, 0, 2),
            ...array_column($small, 'url'),
        ]));
    }

    /** @return array{body: string, extension: string}|null */
    private function download(string $url): ?array
    {
        try {
            $response = Http::timeout(12)->connectTimeout(5)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP logo check)'])->get($url);
        } catch (Throwable) {
            return null;
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();
        if (! $response->successful() || ! isset(self::TYPES[$type]) || strlen($body) < 100 || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        return ['body' => $body, 'extension' => self::TYPES[$type]];
    }

    private static function attribute(string $tag, string $name): string
    {
        if (! preg_match('/(?<![\w-])'.$name.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match)) {
            return '';
        }
        $value = ($match[1] ?? '') !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));

        return html_entity_decode(trim($value), ENT_QUOTES);
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
