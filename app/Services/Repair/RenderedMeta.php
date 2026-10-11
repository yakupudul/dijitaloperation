<?php

namespace App\Services\Repair;

use Illuminate\Support\Facades\DB;

/**
 * What the site really prints in its HTML head, read from the stored crawl (`website_metadata_snapshot`): the SEO
 * plugin's own field can be empty or a template (%%excerpt%%) while the plugin still renders a description from it.
 * Read from collected data only; the site is never contacted.
 */
final class RenderedMeta
{
    /**
     * The latest non-empty rendered meta description per URL (key: URL without trailing slash).
     *
     * @param  iterable<string>  $urls
     * @return array<string, string>
     */
    public static function descriptions(int $siteId, iterable $urls): array
    {
        $variants = collect($urls)->flatMap(fn (string $url): array => [$url, rtrim($url, '/'), rtrim($url, '/').'/'])->unique()->values();
        $out = [];
        foreach ($variants->chunk(500) as $chunk) {
            DB::table('website_metadata_snapshot')->where('digital_asset_id', $siteId)->whereIn('url', $chunk->all())
                ->orderBy('observed_at')->orderBy('id')->get(['url', 'metadata'])
                ->each(function (object $row) use (&$out): void {
                    $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                    $description = self::clean($meta['meta_description'] ?? null);
                    if ($description !== null) {
                        $out[rtrim((string) $row->url, '/')] = $description;
                    }
                });
        }

        return $out;
    }

    public static function description(int $siteId, string $url): ?string
    {
        return self::descriptions($siteId, [$url])[rtrim($url, '/')] ?? null;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' || str_contains($value, '%%') || str_contains($value, '{{') ? null : mb_substr($value, 0, 1000);
    }
}
