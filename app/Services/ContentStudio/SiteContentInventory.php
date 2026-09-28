<?php

namespace App\Services\ContentStudio;

use App\Models\DigitalAsset;
use App\Models\ServicePageAssignment;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the site already has (the whole inventory: crawled page profiles + WordPress posts / pages of the
 * connector snapshot), so the topic map and the studio never propose a topic that is already written. Read only.
 */
final class SiteContentInventory
{
    /** @var list<array{url: string, url_key: string, title: string, h1: string, slug: string, kind: ?string, word_count: ?int, language: ?string, stems: array<string, true>}> */
    private array $items = [];

    /** @var array<string, array<string, mixed>> the plan pages (SeoPlanInputCollector format) keyed by url key */
    private array $pages = [];

    /** @var list<array{name: string, count: int}> */
    private array $categories = [];

    public function __construct(public readonly DigitalAsset $site)
    {
        $this->load();
    }

    public static function for(DigitalAsset $site): self
    {
        return new self($site);
    }

    /** @return list<array{url: string, url_key: string, title: string, h1: string, slug: string, kind: ?string, word_count: ?int, language: ?string, stems: array<string, true>}> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return array<string, array<string, mixed>> plan pages keyed by url key (for SiteUrlPattern) */
    public function pages(): array
    {
        return $this->pages;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array{url: string, url_key: string, title: string, h1: string, slug: string, kind: ?string, word_count: ?int, language: ?string}|null */
    public function byUrl(string $url): ?array
    {
        $key = SeoText::urlKey($url);
        foreach ($this->items as $item) {
            if ($item['url_key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Existing items most similar to a topic text (title, H1 and slug are compared; the best of the three counts).
     *
     * @param  list<string>  $excludeKeys  url keys to skip
     * @return list<array{url: string, title: string, score: float, kind: ?string, word_count: ?int}>
     */
    public function matches(string $text, int $limit = 3, float $minScore = 0.45, array $excludeKeys = []): array
    {
        $stems = TopicText::stems($text);
        if ($stems === []) {
            return [];
        }
        $out = [];
        foreach ($this->items as $item) {
            if (in_array($item['url_key'], $excludeKeys, true)) {
                continue;
            }
            $score = TopicText::stemSimilarity($stems, $item['stems']);
            foreach ([$item['h1'], $item['slug']] as $other) {
                if ($other !== '') {
                    $score = max($score, TopicText::similarity($text, $other));
                }
            }
            if ($score >= $minScore) {
                $out[] = ['url' => $item['url'], 'title' => $item['title'] !== '' ? $item['title'] : $item['url'], 'score' => round($score, 2), 'kind' => $item['kind'], 'word_count' => $item['word_count']];
            }
        }
        usort($out, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $limit);
    }

    /** @return array{url: string, title: string, score: float, kind: ?string, word_count: ?int}|null */
    public function similar(string $text, float $minScore = 0.45): ?array
    {
        return $this->matches($text, 1, $minScore)[0] ?? null;
    }

    /**
     * The page that serves a service: the operator / plan assignment, else the best non-post page naming it.
     *
     * @param  list<string>  $names  service names (primary first)
     * @return array{url: string, title: string}|null
     */
    public function servicePage(?int $offeringId, array $names): ?array
    {
        if ($offeringId !== null) {
            $assigned = ServicePageAssignment::query()->where('digital_asset_id', $this->site->id)->where('brand_offering_id', $offeringId)
                ->where('status', ServicePageAssignment::STATUS_ASSIGNED)->value('page_url');
            if (filled($assigned)) {
                $item = $this->byUrl((string) $assigned);

                return ['url' => (string) $assigned, 'title' => $item['title'] ?? (string) $assigned];
            }
        }
        $best = null;
        foreach ($this->items as $item) {
            if ($item['kind'] === 'post' || SeoText::urlPath($item['url']) === '/') {
                continue;
            }
            foreach ($names as $name) {
                $score = max(TopicText::containment($name, $item['title'].' '.$item['h1']), TopicText::containment($name, $item['slug']));
                $score -= 0.02 * substr_count(trim(SeoText::urlPath($item['url']), '/'), '/');
                if ($score >= 0.8 && ($best === null || $score > $best[0])) {
                    $best = [$score, $item];
                }
            }
        }

        return $best === null ? null : ['url' => $best[1]['url'], 'title' => $best[1]['title'] !== '' ? $best[1]['title'] : $best[1]['url']];
    }

    /** @return list<array{name: string, count: int}> WordPress categories of the site (from the connector snapshot) */
    public function categories(): array
    {
        return $this->categories;
    }

    /** The site's category closest to a service / topic name, if one is close enough. */
    public function category(string $text): ?string
    {
        [$best, $bestScore] = [null, 0.0];
        foreach ($this->categories as $category) {
            $score = max(TopicText::similarity($text, $category['name']), TopicText::containment($category['name'], $text));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$category['name'], $score];
            }
        }

        return $bestScore >= 0.5 ? $best : null;
    }

    private function load(): void
    {
        $collector = app(SeoPlanInputCollector::class);
        foreach ($collector->pages($this->site) as $key => $page) {
            $this->pages[$key] = $page;
            if (($page['status_code'] !== null && $page['status_code'] >= 300) || $page['noindex'] || ($page['cms_status'] !== null && $page['cms_status'] !== 'publish')) {
                continue;
            }
            $this->add((string) $page['url'], (string) ($page['title'] ?? ''), (string) ($page['h1'] ?? ''), is_string($page['cms_type']) ? $page['cms_type'] : null, $page['word_count'], null);
        }
        if (Schema::hasTable('website_cms_object_snapshot')) {
            $latest = [];
            foreach (DB::table('website_cms_object_snapshot')->where('digital_asset_id', $this->site->id)->whereIn('object_type', ['page', 'post'])
                ->orderBy('observed_at')->limit(20000)->get(['object_id', 'object_type', 'status', 'title', 'permalink', 'metadata']) as $row) {
                $latest[(string) $row->object_id] = $row;
            }
            foreach ($latest as $row) {
                if ($row->status !== 'publish' || blank($row->permalink)) {
                    continue;
                }
                $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                $key = SeoText::urlKey((string) $row->permalink);
                $known = false;
                foreach ($this->items as $i => $item) {
                    if ($item['url_key'] === $key) {
                        $this->items[$i]['kind'] ??= (string) $row->object_type;
                        $this->items[$i]['language'] ??= filled($metadata['language'] ?? null) ? (string) $metadata['language'] : null;
                        if ($item['title'] === '' && filled($row->title)) {
                            $this->items[$i]['title'] = TopicText::cleanTitle((string) $row->title);
                            $this->items[$i]['stems'] = TopicText::stems($this->items[$i]['title']);
                        }
                        $known = true;
                        break;
                    }
                }
                if (! $known) {
                    $this->add((string) $row->permalink, (string) $row->title, '', (string) $row->object_type, null, filled($metadata['language'] ?? null) ? (string) $metadata['language'] : null);
                }
            }
        }
        if (Schema::hasTable('website_cms_taxonomy_snapshot')) {
            $terms = [];
            foreach (DB::table('website_cms_taxonomy_snapshot')->where('digital_asset_id', $this->site->id)->where('taxonomy', 'category')
                ->orderBy('observed_at')->limit(2000)->get(['term_id', 'name', 'content_count']) as $row) {
                if (filled($row->name)) {
                    $terms[(string) $row->term_id] = ['name' => html_entity_decode((string) $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'count' => (int) $row->content_count];
                }
            }
            $this->categories = array_values(array_filter($terms, fn (array $t): bool => ! in_array(SeoText::fold($t['name']), ['uncategorized', 'genel', 'kategorisiz'], true)));
        }
    }

    private function add(string $url, string $title, string $h1, ?string $kind, ?int $wordCount, ?string $language): void
    {
        $title = TopicText::cleanTitle($title);
        $h1 = trim($h1);
        $this->items[] = [
            'url' => $url, 'url_key' => SeoText::urlKey($url), 'title' => $title !== '' ? $title : $h1, 'h1' => $h1,
            'slug' => SeoText::slugText($url), 'kind' => $kind, 'word_count' => $wordCount, 'language' => $language,
            'stems' => TopicText::stems($title !== '' ? $title : ($h1 !== '' ? $h1 : SeoText::slugText($url))),
        ];
    }
}
