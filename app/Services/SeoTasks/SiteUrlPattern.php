<?php

namespace App\Services\SeoTasks;

/**
 * Where a new page belongs on this site, read from the site's own URLs (page inventory). Pure; no I/O.
 *
 * - Articles (guide) go under the folder the site's posts already use (/blog/, /bloglar/, /makaleler/ …) or at
 *   the root when posts live at root slugs (WordPress default). /blog/ is never invented.
 * - Service pages go under the dominant service section (/tedavilerimiz/, /hizmetlerimiz/ …) and,
 *   when a sibling service page sits in a category folder (/tedavilerimiz/implant/…), next to that sibling.
 * - Location and FAQ pages stay at root slugs.
 */
final class SiteUrlPattern
{
    /** First path segments that mark an article/post section. */
    public const array POST_SECTIONS = ['blog', 'bloglar', 'makale', 'makaleler', 'yazilar', 'yazi', 'haberler', 'haber', 'rehber', 'articles', 'news', 'posts', 'saglik-rehberi'];

    /** First path segments that mark a service section. */
    public const array SERVICE_SECTIONS = ['tedavilerimiz', 'tedaviler', 'tedavi', 'hizmetlerimiz', 'hizmetler', 'hizmet', 'uygulamalar', 'uygulamalarimiz', 'bolumlerimiz', 'bolumler', 'services', 'treatments', 'urunler', 'urunlerimiz'];

    /** @var list<array{path: string, segments: list<string>, cms_type: ?string}> */
    private array $pages = [];

    /** @param  iterable<array<string, mixed>>  $pages  plan pages (url / path / cms_type) */
    public function __construct(iterable $pages)
    {
        foreach ($pages as $page) {
            $path = (string) ($page['path'] ?? SeoText::urlPath((string) ($page['url'] ?? '')));
            $segments = array_values(array_filter(explode('/', mb_strtolower(trim($path, '/'))), static fn (string $s): bool => $s !== ''));
            if ($segments === []) {
                continue;
            }
            $this->pages[] = ['path' => $path, 'segments' => $segments, 'cms_type' => isset($page['cms_type']) && is_string($page['cms_type']) ? $page['cms_type'] : null];
        }
    }

    /** Folder for articles: "/" (root slugs) or "/blog/"-style prefix actually used by the site. */
    public function postBase(): string
    {
        $posts = array_values(array_filter($this->pages, static fn (array $p): bool => $p['cms_type'] === 'post'));
        if ($posts !== []) {
            // The CMS says which URLs are posts: use their folder only when most of them share one.
            $nested = array_filter($posts, static fn (array $p): bool => count($p['segments']) >= 2);
            if (count($nested) * 2 < count($posts)) {
                return '/';
            }
            [$segment, $count] = $this->dominantFirstSegment($nested);

            return $segment !== null && $count * 2 >= count($posts) ? '/'.$segment.'/' : '/';
        }

        $candidates = array_filter($this->pages, static fn (array $p): bool => count($p['segments']) >= 2 && in_array($p['segments'][0], self::POST_SECTIONS, true));
        [$segment] = $this->dominantFirstSegment($candidates);

        return $segment !== null ? '/'.$segment.'/' : '/';
    }

    /** Folder for a new service page of $serviceName: sibling's category folder, the service section, or "/". */
    public function serviceBase(string $serviceName = ''): string
    {
        $candidates = array_filter($this->pages, static fn (array $p): bool => count($p['segments']) >= 2 && in_array($p['segments'][0], self::SERVICE_SECTIONS, true));
        [$section] = $this->dominantFirstSegment($candidates);
        if ($section === null) {
            return '/';
        }

        // Category folder: the best-matching existing service page under the section that sits one level deeper.
        if (trim($serviceName) !== '') {
            $best = null;
            $bestScore = 0.0;
            foreach ($candidates as $page) {
                if ($page['segments'][0] !== $section || count($page['segments']) < 3) {
                    continue;
                }
                $folder = array_slice($page['segments'], 0, -1);
                $score = max(
                    // category words named by the service ("implant" folder for "İmplant Üstü Protez")
                    SeoText::tokenOverlap($serviceName, str_replace('-', ' ', implode(' ', array_slice($folder, 1)))),
                    SeoText::tokenOverlap(str_replace('-', ' ', (string) end($page['segments'])), $serviceName),
                );
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $folder;
                }
            }
            if ($best !== null && $bestScore >= 0.5) {
                return '/'.implode('/', $best).'/';
            }
        }

        return '/'.$section.'/';
    }

    /** Full target URL for a new page of the given plan type (guide / faq / location / service). */
    public function targetUrl(string $origin, string $type, string $slug, string $serviceName = ''): string
    {
        $base = match ($type) {
            'guide' => $this->postBase(),
            'service' => $this->serviceBase($serviceName),
            default => '/',
        };

        return rtrim($origin, '/').$base.trim($slug, '/').'/';
    }

    /**
     * @param  iterable<array{segments: list<string>}>  $pages
     * @return array{0: ?string, 1: int}
     */
    private function dominantFirstSegment(iterable $pages): array
    {
        $counts = [];
        foreach ($pages as $page) {
            $counts[$page['segments'][0]] = ($counts[$page['segments'][0]] ?? 0) + 1;
        }
        if ($counts === []) {
            return [null, 0];
        }
        arsort($counts);
        $segment = (string) array_key_first($counts);

        return [$segment, $counts[$segment]];
    }
}
