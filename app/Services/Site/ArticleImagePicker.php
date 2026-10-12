<?php

namespace App\Services\Site;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The article's featured image from the site's own media library (yakup, 2026-10-12 "yazı için web sitesinde en uygun
 * görsel bulunup seçilsin, yoksa en yakın görsel"). Rules only, no AI and no upload: every image of the site's media
 * snapshot is scored by the idea's words (title, cluster, target address) in its alt text, title and file name and in
 * the page it belongs to (attached to it or its featured image). Without a word match the nearest page's featured
 * image comes instead. The connector (1.14.0) sets the chosen media id as the draft's featured image.
 */
final class ArticleImagePicker
{
    public const int CANDIDATES = 5;

    private const int MIN_SIDE = 500;

    /** Words that name no subject (question words and filler of article titles). */
    private const array GENERIC = ['nedir', 'neden', 'nasil', 'nelerdir', 'kac', 'hangi', 'zaman', 'sonra', 'once', 'olur', 'yapilir', 'gerekir',
        'mudur', 'midir', 'icin', 'ilk', 'hafta', 'gun', 'rehber', 'rehberi', 'hakkinda', 'merak', 'edilenler', 'blog', 'what', 'how', 'why', 'when',
        'guide', 'your', 'with', 'after', 'before', 'wp', 'content', 'uploads', 'scaled', 'image', 'img', 'jpg', 'jpeg', 'png', 'webp', 'gorsel', 'resim'];

    /**
     * Best images first; empty when the site has no usable image in its media snapshot.
     *
     * @return list<array{media_id: int, url: string, alt: string, why: string}>
     */
    public function candidates(Suggestion $suggestion, DigitalAsset $site, int $limit = self::CANDIDATES): array
    {
        $action = (array) $suggestion->action;
        $words = self::words(implode(' ', array_filter([(string) $suggestion->title, (string) $suggestion->cluster?->name,
            (string) parse_url((string) ($action['target_url'] ?? ''), PHP_URL_PATH)])));
        if ($words === []) {
            return [];
        }
        $rows = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->where('object_type', '!=', 'nav_menu_item')
            ->orderByDesc('id')->limit(8000)->get(['object_type', 'object_id', 'status', 'parent_id', 'permalink', 'title', 'slug', 'featured_media_id', 'metadata'])
            ->unique(fn (object $row): string => $row->object_type.':'.$row->object_id);
        $pages = $rows->where('object_type', '!=', 'attachment')->filter(fn (object $row): bool => $row->status === 'publish')->keyBy(fn (object $row): string => (string) $row->object_id);
        $featuredOn = [];
        foreach ($pages as $page) {
            if (filled($page->featured_media_id)) {
                $featuredOn[(string) $page->featured_media_id][] = $page;
            }
        }
        $list = [];
        foreach ($rows->where('object_type', 'attachment') as $row) {
            $image = self::usable($row);
            if ($image === null) {
                continue;
            }
            $own = self::matches($words, self::words(implode(' ', [$image['alt'], (string) $row->title, basename($image['url'])])));
            $related = collect([$pages->get((string) $row->parent_id), ...($featuredOn[(string) $row->object_id] ?? [])])->filter();
            $pageMatch = $related->map(fn (object $page): array => ['page' => $page, 'hits' => self::matches($words, self::words($page->title.' '.$page->slug))])
                ->sortByDesc('hits')->first();
            $score = 3 * $own + 2 * (int) ($pageMatch['hits'] ?? 0) + (isset($featuredOn[(string) $row->object_id]) ? 1 : 0);
            $why = $own > 0 ? 'görselin adı ve alt metni konuyla eşleşiyor'
                : (($pageMatch['hits'] ?? 0) > 0 ? '«'.mb_substr((string) $pageMatch['page']->title, 0, 60).'» sayfasının görseli' : null);
            $list[] = $image + ['score' => $score, 'why' => $why, 'size' => $image['size']];
        }
        $matched = array_values(array_filter($list, fn (array $c): bool => $c['score'] >= 2 && $c['why'] !== null));
        if ($matched === []) {
            $matched = $this->nearestPageImages($words, $pages, collect($list)->keyBy('media_id'));
        }
        usort($matched, fn (array $a, array $b): int => [$b['score'], $b['size']] <=> [$a['score'], $a['size']]);

        return array_map(fn (array $c): array => ['media_id' => $c['media_id'], 'url' => $c['url'], 'alt' => $c['alt'], 'why' => (string) $c['why']],
            array_slice($matched, 0, $limit));
    }

    /**
     * No image names the subject: the featured images of the pages closest to it (most shared words; the site's
     * pages in general when none shares a word).
     *
     * @param  list<string>  $words
     * @param  Collection<string, object>  $pages
     * @param  Collection<int, array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    private function nearestPageImages(array $words, Collection $pages, Collection $images): array
    {
        $out = [];
        foreach ($pages as $page) {
            $image = $images->get((int) $page->featured_media_id);
            if ($image === null || isset($out[$image['media_id']])) {
                continue;
            }
            $hits = self::matches($words, self::words($page->title.' '.$page->slug));
            $out[$image['media_id']] = ['score' => $hits, 'why' => ($hits > 0 ? 'en yakın sayfa: ' : 'sitenin görseli: ').'«'.mb_substr((string) $page->title, 0, 60).'»'] + $image;
        }

        return array_values($out);
    }

    /** @return array{media_id: int, url: string, alt: string, size: int}|null */
    private static function usable(object $row): ?array
    {
        $url = trim((string) $row->permalink);
        $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
        $mime = strtolower((string) ($meta['mime_type'] ?? ''));
        $width = isset($meta['width']) ? (int) $meta['width'] : null;
        $height = isset($meta['height']) ? (int) $meta['height'] : null;
        $name = SeoText::fold(basename($url).' '.$row->title);
        if ($url === '' || preg_match('~^https?://\S+\.(jpe?g|png|webp)$~i', $url) !== 1 || ($mime !== '' && ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true))
            || ($width !== null && $width < self::MIN_SIDE) || ($height !== null && $height < self::MIN_SIDE / 2)
            || preg_match('/(?<![a-z])(logo|icon|ikon|favicon|placeholder|banner|avatar)/', $name) === 1) {
            return null;
        }

        return ['media_id' => (int) $row->object_id, 'url' => $url, 'alt' => trim((string) ($meta['alt_text'] ?? '')), 'size' => ($width ?? 0) * ($height ?? 0)];
    }

    /** @return list<string> subject words, folded */
    private static function words(string $text): array
    {
        return array_values(array_filter(SeoText::tokens(str_replace(['-', '_', '/'], ' ', $text)),
            fn (string $w): bool => mb_strlen($w) >= 3 && ! is_numeric($w) && ! in_array($w, self::GENERIC, true)));
    }

    /**
     * Subject words the text shares, Turkish suffixes aside ("implant" = "implanti"): the same first five letters.
     *
     * @param  list<string>  $words
     * @param  list<string>  $text
     */
    private static function matches(array $words, array $text): int
    {
        $stem = fn (string $w): string => mb_substr($w, 0, 5);
        $have = array_flip(array_map($stem, $text));

        return count(array_filter(array_unique(array_map($stem, $words)), fn (string $w): bool => isset($have[$w])));
    }
}
